<?php

namespace App\Services;

use App\Core\Enum\Message;
use App\Exceptions\CustomErrorException;
use App\Models\Player;
use App\Models\Tournament;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si un jugador puede entrar a un torneo: rama y edad. Lista para la solicitud de roster, que aún no existe.
 * El rechazo es neutro a propósito: no dice qué requisito falló ni la rama del jugador.
 */
class PlayerEligibility
{
    public function isEligible(Player $player, Tournament $tournament): bool
    {
        return $tournament->gender->accepts($player->gender) && $this->ageFits($player, $tournament);
    }

    /**
     * @throws CustomErrorException
     */
    public function assertEligible(Player $player, Tournament $tournament): void
    {
        if (! $this->isEligible($player, $tournament)) {
            throw new CustomErrorException(Message::PLAYER_NOT_ELIGIBLE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Edad cumplida a la fecha de inicio del torneo contra el mínimo y el máximo (vacío = sin límite).
     */
    private function ageFits(Player $player, Tournament $tournament): bool
    {
        $age = (int) floor($tournament->start_date->diffInYears($player->birth_date, true));

        return ($tournament->min_age === null || $age >= $tournament->min_age)
            && ($tournament->max_age === null || $age <= $tournament->max_age);
    }
}
