<?php

namespace App\Contracts\Services;

use App\Core\Contracts\BaseServiceInterface;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Tournament;
use App\Models\User;

/**
 * Reglas del roster. Aún no hay endpoints: el flujo (solicitud del delegado, respuesta del jugador, baja del
 * admin) se expondrá después sobre estos métodos. Todos corren en una transacción con las filas bloqueadas y
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
     * Solo el admin de la liga o el superadmin, con el torneo en planeación o en curso y con motivo obligatorio.
     * El jugador conserva sus asistencias y estadísticas con ese equipo.
     */
    public function removePlayer(Roster $roster, User $actor, string $reason): Roster;

    /**
     * Solo el admin de la liga o el superadmin: permite que un jugador quitado se sume a otro equipo del torneo.
     */
    public function authorizeRejoin(Roster $roster, User $actor): Roster;

    /**
     * Cierra las plantillas al entrar a la liguilla: las solicitudes pending pasan a cancelled. Devuelve cuántas.
     */
    public function closeRosters(Tournament $tournament): int;
}
