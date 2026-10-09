<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\LeagueRefereeStatus;
use App\Data\Referees\RefereeData;
use App\Models\League;
use App\Models\LeagueReferee;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Todas las operaciones se acotan a la liga recibida; un árbitro no vinculado a ella es 404.
 *
 * @extends BaseServiceInterface<LeagueReferee>
 */
interface LeagueRefereeServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, LeagueReferee>
     */
    public function paginateByLeague(League $league, ListQuery $query, ?string $search, ?LeagueRefereeStatus $status): LengthAwarePaginator;

    /**
     * Crea un usuario pendiente con rol referee, lo vincula a la liga y le envía la invitación.
     */
    public function createReferee(League $league, RefereeData $data): LeagueReferee;

    /**
     * Datos mínimos de un árbitro activo por su user_code; 404 genérico si no es elegible.
     *
     * @return array{user: User, already_in_league: bool}
     */
    public function lookup(League $league, string $userCode): array;

    /**
     * Vincula (o reactiva/restaura) a un árbitro existente por su user_code.
     */
    public function attach(League $league, string $userCode): LeagueReferee;

    public function changeStatus(League $league, int $userId, LeagueRefereeStatus $status): LeagueReferee;

    /**
     * Baja lógica del vínculo; no toca la cuenta ni las otras ligas.
     */
    public function detach(League $league, int $userId): void;

    /**
     * Solo con cuenta pendiente y vinculado únicamente a esta liga; si no, 422.
     */
    public function updateReferee(League $league, int $userId, RefereeData $data): LeagueReferee;

    public function resendInvitation(League $league, int $userId): LeagueReferee;
}
