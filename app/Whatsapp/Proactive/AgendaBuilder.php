<?php

namespace App\Whatsapp\Proactive;

use App\Models\AutoReplySetting;
use Illuminate\Support\Carbon;

/**
 * Proativas P-2 — materializa a AGENDA de uma campanha aprovada (so o calculo e
 * a gravacao; o tick/consumo e a P-3). Horarios SEMPRE dentro da janela proativa
 * (fuso SP), espacados com jitter aleatorio entre proactive_jitter_min e _max
 * (D5: 3-15min). O que nao cabe na janela de hoje TRANSBORDA pro proximo dia na
 * abertura da janela ("dia util" da janela = o proximo dia em que ela abre; a
 * janela nao distingue dias da semana nesta fase).
 */
class AgendaBuilder
{
    /**
     * @return array<int,Carbon>  $count horarios em UTC, ordenados
     */
    public function build(AutoReplySetting $settings, ?Carbon $from, int $count): array
    {
        $tz = (string) config('app.display_timezone');
        $jitterMin = max(1, (int) $settings->proactive_jitter_min);
        $jitterMax = max($jitterMin, (int) $settings->proactive_jitter_max);
        [$startH, $startM] = $this->hm((string) $settings->proactive_window_start, 9, 0);
        [$endH, $endM] = $this->hm((string) $settings->proactive_window_end, 18, 0);

        // Cursor em horario LOCAL (SP), nunca antes de agora/start_at.
        $cursor = ($from ?: Carbon::now())->copy()->setTimezone($tz);
        $cursor = $this->dentroDaJanela($cursor, $startH, $startM, $endH, $endM);

        $horarios = [];
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                $cursor = $cursor->copy()->addMinutes(random_int($jitterMin, $jitterMax));
                $cursor = $this->dentroDaJanela($cursor, $startH, $startM, $endH, $endM);
            }
            $horarios[] = $cursor->copy()->utc();
        }

        return $horarios;
    }

    /** Alinha o cursor pra DENTRO da janela (antes -> abre hoje; depois -> abre amanha). */
    private function dentroDaJanela(Carbon $local, int $sh, int $sm, int $eh, int $em): Carbon
    {
        $abertura = $local->copy()->setTime($sh, $sm, 0);
        $fechamento = $local->copy()->setTime($eh, $em, 0);

        if ($local->lt($abertura)) {
            return $abertura;
        }
        if ($local->gt($fechamento)) {
            // Transborda pro PROXIMO dia, na abertura.
            return $abertura->addDay();
        }

        return $local;
    }

    /** @return array{0:int,1:int} */
    private function hm(string $time, int $defH, int $defM): array
    {
        $partes = explode(':', $time);

        return [
            isset($partes[0]) && is_numeric($partes[0]) ? (int) $partes[0] : $defH,
            isset($partes[1]) && is_numeric($partes[1]) ? (int) $partes[1] : $defM,
        ];
    }
}
