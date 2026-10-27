<?php

namespace App\Helpers;

use Carbon\CarbonImmutable;

/**
 * Espacios de juego de una semana (lunes a domingo). Los espacios de una ventana son consecutivos desde su hora de
 * inicio, de `duración` minutos y sin margen; solo existe el que termina antes o a la hora fin de la ventana.
 * Un espacio se pierde si se traslapa con un bloqueo de su fecha y cancha (o de todas las canchas).
 * Las horas viajan como minutos desde la medianoche.
 */
class ScheduleCapacity
{
    /**
     * "08:00" o "08:00:00" a minutos desde la medianoche.
     */
    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param  array{start: int, end: int}  $a
     * @param  array{start: int, end: int}  $b
     */
    public static function overlap(array $a, array $b): bool
    {
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    /**
     * Espacios de la semana que empieza en $monday, ordenados por fecha, hora y cancha. Los días anteriores a
     * $startDate no se usan.
     *
     * @param  list<array{weekday: int, field_id: int, start: int, end: int}>  $windows
     * @param  list<array{date: string, field_id: int|null, start: int|null, end: int|null}>  $blocks
     * @return list<array{date: string, field_id: int, start: int, end: int}>
     */
    public static function weekSlots(CarbonImmutable $monday, CarbonImmutable $startDate, array $windows, array $blocks, int $duration): array
    {
        $slots = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $monday->addDays($offset);

            if ($day->lt($startDate)) {
                continue;
            }

            $date = $day->toDateString();
            $dayBlocks = array_values(array_filter($blocks, fn (array $block) => $block['date'] === $date));

            foreach ($windows as $window) {
                if ($window['weekday'] !== $day->dayOfWeekIso) {
                    continue;
                }

                for ($start = $window['start']; $start + $duration <= $window['end']; $start += $duration) {
                    $slot = ['date' => $date, 'field_id' => $window['field_id'], 'start' => $start, 'end' => $start + $duration];

                    if (! self::blocked($slot, $dayBlocks)) {
                        $slots[] = $slot;
                    }
                }
            }
        }

        usort($slots, fn (array $a, array $b) => [$a['date'], $a['start'], $a['field_id']] <=> [$b['date'], $b['start'], $b['field_id']]);

        return $slots;
    }

    /**
     * @param  array{date: string, field_id: int, start: int, end: int}  $slot
     * @param  list<array{date: string, field_id: int|null, start: int|null, end: int|null}>  $blocks  Ya de la fecha del espacio.
     */
    private static function blocked(array $slot, array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block['field_id'] !== null && $block['field_id'] !== $slot['field_id']) {
                continue;
            }

            if ($block['start'] === null || $block['end'] === null || self::overlap($slot, ['start' => $block['start'], 'end' => $block['end']])) {
                return true;
            }
        }

        return false;
    }
}
