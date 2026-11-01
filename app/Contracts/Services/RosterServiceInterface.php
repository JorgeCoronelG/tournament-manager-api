<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\RosterStatus;
use App\Models\League;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reglas del roster. El panel expone la consulta y la moderación (listar, quitar, reingreso); la solicitud del
 * delegado y la respuesta del jugador se expondrán en la app sobre estos métodos. Todos corren en una transacción con las filas bloqueadas y
 * validan quién actúa (403) además de las reglas de negocio (422).
 *
 * @extends BaseServiceInterface<Roster>
 */
interface RosterServiceInterface extends BaseServiceInterface
{
    /**
     * El delegado del equipo envía una solicitud (queda pending). 422 si las plantillas están cerradas
     * (torneo no abierto o en liguilla), el jugador no cumple rama y edad, tiene veto en la liga, ya está activo
     * en otro equipo del torneo o fue quitado de otro sin autorización de reingreso, ya tiene una solicitud
     * abierta con este equipo, el dorsal está en uso o el equipo llegó a `max_roster_size`.
     */
    public function requestPlayer(Registration $registration, Player $player, int $jerseyNumber, User $actor): Roster;

    /**
     * El propio jugador acepta: pasa a active y sus otras solicitudes pending del torneo, a cancelled.
     */
    public function accept(Roster $roster, User $actor): Roster;

    /**
     * El propio jugador rechaza: pasa a rejected y el lugar y el dorsal quedan libres.
     */
    public function reject(Roster $roster, User $actor): Roster;

    /**
     * El delegado del equipo retira una solicitud que sigue pendiente: pasa a cancelled y el lugar y el dorsal
     * quedan libres. A un jugador ya aceptado solo lo quita el admin de la liga (removePlayer).
     */
    public function cancelRequest(Roster $roster, User $actor): Roster;

    /**
     * Jugador por su clave exacta, para que el delegado lo vea antes de mandarle la solicitud. 404 neutro si la
     * clave no existe o no es de un jugador.
     */
    public function findPlayerByCode(string $userCode): Player;

    /**
     * Inscripciones de los equipos que dirige el delegado, con equipo, torneo y liga cargados y los conteos de
     * su plantilla (`active_count`, `pending_count`). Primero las de torneos abiertos.
     *
     * @return Collection<int, Registration>
     */
    public function registrationsOfManager(User $manager): Collection;

    /**
     * Plantilla completa de una inscripción para su delegado (403 si el equipo no es suyo): activos, pendientes y
     * el resto, por dorsal.
     *
     * @return Collection<int, Roster>
     */
    public function rosterForManager(Registration $registration, User $manager): Collection;

    /**
     * Solicitudes pendientes del jugador (la más reciente primero), con equipo, torneo y delegado cargados.
     *
     * @return Collection<int, Roster>
     */
    public function pendingRequestsOf(User $player): Collection;

    /**
     * Solo el admin de la liga o el superadmin, con el torneo en planeación o en curso y con motivo obligatorio.
     * El jugador conserva sus asistencias y estadísticas con ese equipo.
     */
    public function removePlayer(Roster $roster, User $actor, string $reason): Roster;

    /**
     * Solo el admin de la liga o el superadmin: permite que un jugador quitado se sume a otro equipo del torneo.
     */
    public function authorizeRejoin(Roster $roster, User $actor): Roster;

    /**
     * Revoca el reingreso autorizado mientras el jugador no tenga una solicitud nueva en el torneo.
     */
    public function revokeRejoin(Roster $roster, User $actor): Roster;

    /**
     * Roster paginado de la inscripción (búsqueda por nombre o clave del jugador, estado y orden opcionales) y sus
     * conteos sobre toda la plantilla, sin importar la página ni la búsqueda; los activos y pendientes cuentan
     * para el máximo del torneo.
     *
     * @return array{rows: LengthAwarePaginator<int, Roster>, summary: array{active: int, pending: int, max_roster_size: int|null}}
     */
    public function listForRegistration(Registration $registration, ListQuery $query, ?string $search, ?RosterStatus $status): array;

    /**
     * Regla del veto, para que la use el endpoint de vetar jugadores: al vetar a un jugador en una liga, sus
     * filas active pasan a removed (con el motivo del veto) y las pending a cancelled, en los torneos en
     * planeación o en curso de esa liga. Reactivarlo no las restaura. Se une a la transacción de quien la llame.
     * Devuelve cuántas plantillas quitó y cuántas solicitudes canceló.
     *
     * @return array{removed: int, cancelled: int}
     */
    public function releaseBannedPlayer(Player $player, League $league, User $actor, string $reason): array;

    /**
     * Cierra las plantillas al entrar a la liguilla: las solicitudes pending pasan a cancelled. Devuelve cuántas.
     */
    public function closeRosters(Tournament $tournament): int;
}
