<?php

namespace App\Core\Enum;

class Message
{
    // Mensajes de validaciones
    public const CREDENTIALS_INVALID = 'Credenciales inválidas.';

    public const CURRENT_PASSWORD_INVALID = 'Contraseña actual incorrecta.';

    public const CODE_INVALID_OR_EXPIRED = 'Código inválido o expirado.';

    public const TOO_MANY_CODE_ATTEMPTS = 'Demasiados intentos fallidos. Solicite un nuevo código.';

    public const INVALID_QUERY_PARAMETER = 'Parámetro de consulta inválido.';

    public const INVALID_ID_PARAMETER_WITH_ID_BODY = 'El id es diferente al id del parámetro de ruta.';

    // Mensajes de excepciones
    public const AUTHENTICATION_EXCEPTION = 'No autenticado.';

    public const MODEL_NOT_FOUND_EXCEPTION = 'No existe el registro.';

    public const AUTHORIZATION_EXCEPTION = 'No tiene permisos para ejecutar esta acción.';

    public const NOT_FOUND_HTTP_EXCEPTION = 'No se encontró la URL especificada.';

    public const METHOD_NOT_ALLOWED_HTTP_EXCEPTION = 'Método no válido.';

    public const QUERY_EXCEPTION_1451 = 'No se puede eliminar el registro porque está relacionado con algún otro.';

    public const INTERNAL_SERVER_ERROR = 'Ocurrió algo inesperado. Consulte al administrador.';

    public const THROTTLE_REQUESTS_EXCEPTION = 'Muchos intentos realizados.';

    public const SUPERADMIN_PROTECTED = 'No se puede gestionar una cuenta de superadministrador.';

    public const CANNOT_MODIFY_SELF = 'No puede realizar esta acción sobre su propia cuenta.';

    public const ACCESS_ONLY_ADMINS = 'Este acceso es solo para administradores. Usa la app móvil.';

    public const ACCESS_ONLY_MOBILE_USERS = 'Este acceso es solo para árbitros y jugadores. Usa el panel web.';

    public const ACCOUNT_INACTIVE = 'La cuenta está desactivada.';

    public const INVITATION_ALREADY_ACTIVE = 'La cuenta ya está activa.';

    public const INVALID_ROLE = 'El rol indicado no es válido.';

    public const TOURNAMENT_NAME_TAKEN = 'El nombre ya está en uso en esta liga.';

    public const TOURNAMENT_FINISHED_NOT_EDITABLE = 'Un torneo finalizado no se puede editar.';

    public const TOURNAMENT_IN_PROGRESS_ONLY_NAME = 'Un torneo en curso solo permite cambiar el nombre.';

    public const TOURNAMENT_DELETE_ONLY_PLANNING = 'Solo se puede eliminar un torneo en planeación.';

    public const FIELD_NAME_TAKEN = 'El nombre ya está en uso en esta liga.';

    public const REFEREE_DUPLICATE_USER = 'Ya existe un usuario con este dato.';

    public const REFEREE_ALREADY_IN_LEAGUE = 'El árbitro ya está activo en esta liga.';

    public const REFEREE_EDIT_NOT_ALLOWED = 'Solo el propio árbitro puede cambiar sus datos: su cuenta ya está activada o pertenece a otras ligas.';

    public const TEAM_NAME_TAKEN_BY_MANAGER = 'Este delegado ya tiene un equipo con ese nombre.';

    public const TEAM_NAME_TAKEN_WITHOUT_MANAGER = 'Ya existe un equipo sin delegado con ese nombre. Asigna un delegado para distinguirlos.';

    public const TEAM_MANAGER_DUPLICATE_USER = 'Ya existe un usuario con este dato.';

    public const TEAM_MANAGER_BOTH_FORMS = 'Indique el delegado por su clave o con sus datos, no ambos.';

    public const TEAM_MANAGER_REQUIRED = 'Indique el delegado por su clave o con sus datos.';

    public const TEAM_MANAGER_EDIT_NOT_ALLOWED = 'Solo el propio delegado puede cambiar sus datos: su cuenta ya está activada o es delegado de equipos de otras ligas.';

    public const TEAM_HAS_NO_MANAGER = 'El equipo no tiene delegado.';

    public const TEAM_HAS_ACTIVE_REGISTRATIONS = 'El equipo está inscrito en un torneo. Quítalo del torneo primero.';

    public const TOURNAMENT_START_NEEDS_TEAMS = 'El torneo necesita al menos 2 equipos inscritos y no vetados para iniciar.';

    public const TOURNAMENT_SCHEDULE_HAS_BANNED_TEAMS = 'Quita o reactiva a los equipos vetados antes de continuar.';

    public const PLAYER_NOT_ELIGIBLE = 'Este jugador no cumple los requisitos del torneo.';

    public const PLAYER_NOT_FOUND = 'No existe el registro.';

    public const REGISTRATION_TOURNAMENT_NOT_PLANNING = 'Solo se pueden inscribir equipos en un torneo en planeación.';

    public const REGISTRATION_REMOVE_ONLY_PLANNING = 'Solo se puede quitar la inscripción de un torneo en planeación.';

    public const REGISTRATION_TEAM_NOT_IN_LEAGUE = 'El equipo no pertenece a esta liga.';

    public const REGISTRATION_TEAM_BANNED = 'El equipo está vetado y no puede inscribirse.';

    public const REGISTRATION_TEAM_ALREADY_REGISTERED = 'El equipo ya está inscrito en este torneo.';

    public const REGISTRATION_SPORT_MISMATCH = 'El equipo y el torneo deben ser del mismo deporte.';

    public const TOURNAMENT_SPORT_LOCKED = 'No se puede cambiar el deporte de un torneo con equipos inscritos.';

    public const TEAM_SPORT_LOCKED = 'No se puede cambiar el deporte de un equipo con inscripciones activas.';

    public const REGISTRATION_TEAM_NAME_TAKEN = 'Ya hay un equipo con ese nombre en este torneo.';

    public const PLAYER_ALREADY_BANNED = 'El jugador ya está vetado en esta liga.';

    public const PLAYER_NOT_BANNED = 'El jugador no está vetado en esta liga.';

    public static function getMessagePlayerGenderLocked(string $tournaments): string
    {
        return "No se puede cambiar la rama porque el jugador está inscrito en: {$tournaments}. Primero da de baja al jugador de esos torneos.";
    }

    public const TOURNAMENT_PLAYOFFS_STARTED_LOCKED = 'La liguilla ya inició: no se pueden modificar el máximo de jugadores ni los partidos mínimos.';

    public const TOURNAMENT_CONFIG_PLANNING_ONLY = 'Con el torneo en curso solo se puede editar el nombre.';

    public const TOURNAMENT_START_NEEDS_GROUPS = 'Todos los equipos inscritos deben tener un grupo para iniciar el torneo.';

    public const REGISTRATION_GROUP_INVALID = 'El grupo debe ser de fase regular y pertenecer al mismo torneo.';

    public const GROUPS_TOURNAMENT_IN_PROGRESS = 'Con el torneo en curso no se pueden modificar los grupos.';

    public const GROUPS_TOURNAMENT_FINISHED = 'Con el torneo terminado no se pueden modificar los grupos.';

    public const MATCHES_GENERATE_ONLY_IN_PROGRESS = 'El calendario solo se genera con el torneo en curso.';

    public const MATCHES_REGENERATE_HAS_PLAYED = 'Ya hay partidos jugados; el calendario no se puede regenerar.';

    public const MATCHES_DELETE_ONLY_IN_PROGRESS = 'El calendario solo se puede borrar con el torneo en curso.';

    public const MATCHES_DELETE_HAS_PLAYED = 'Ya hay partidos jugados; el calendario no se puede borrar.';

    public const SCHEDULE_NOT_EDITABLE = 'El horario del torneo ya no se puede modificar.';

    public const SCHEDULE_TIME_ORDER = 'La hora de inicio debe ser menor que la de fin.';

    public const SCHEDULE_WINDOWS_OVERLAP = 'Las ventanas de una cancha no pueden empalmarse.';

    public const SCHEDULE_FIELD_UNAVAILABLE = 'Una de las canchas no está disponible.';

    public const SCHEDULE_WINDOW_TOO_SHORT = 'Una ventana es más corta que la duración del partido.';

    public const SCHEDULE_START_DATE_REQUIRED = 'Indica la fecha de inicio del calendario.';

    public const SCHEDULE_BLOCK_TIME_PAIR = 'Indica la hora de inicio y la de fin del bloqueo, o ninguna.';

    public const SCHEDULE_DURATION_REQUIRED = 'Define la duración del partido en el torneo.';

    public const SCHEDULE_ONLY_IN_PROGRESS = 'El horario solo se genera con el torneo en curso.';

    public const SCHEDULE_NO_CALENDAR = 'Primero genera el calendario de partidos.';

    public const SCHEDULE_NOT_CONFIGURED = 'Configura los horarios del torneo primero.';

    public const MATCH_SCHEDULE_ONLY_IN_PROGRESS = 'El horario solo se edita con el torneo en curso o en liguilla.';

    public const SCHEDULE_PLAYOFFS_ONLY = 'Los horarios de liguilla se generan con el torneo en liguilla.';

    public const SCHEDULE_NO_PLAYOFF_MATCHES = 'La liguilla aún no tiene partidos.';

    public const SCHEDULE_FINALS_NOT_ALIGNED = 'Las finales de las liguillas no están en la misma fecha.';

    public const AGENDA_RANGE_TOO_LONG = 'El rango de fechas no puede ser mayor a 31 días.';

    public const AGENDA_RANGE_INVALID = 'La fecha de inicio no puede ser posterior a la de fin.';

    public const MATCH_SCHEDULE_PLAYED = 'No se puede reprogramar un partido jugado.';

    public const MATCH_SCHEDULE_FIELD_NEEDS_DATETIME = 'Indica la fecha y hora.';

    public const MATCH_SCHEDULE_FIELD_UNAVAILABLE = 'La cancha no está disponible.';

    public const MATCH_SCHEDULE_REFEREE_INACTIVE = 'El árbitro no está activo en la liga.';

    public const MATCH_SCHEDULE_REFEREE_PLAYS = 'El árbitro juega en uno de los equipos.';

    public const MATCH_SWAP_SAME_MATCH = 'Elige otro partido para intercambiar.';

    public const MATCH_SWAP_DIFFERENT_TOURNAMENT = 'Solo se pueden intercambiar partidos del mismo torneo.';

    public const MATCH_SWAP_NOT_FOUND = 'El partido indicado no existe en la liga.';

    public const MATCH_WARNING_OUTSIDE_SCHEDULE = 'Fuera de los horarios configurados del torneo.';

    public const MATCH_WARNING_PAST_DATE = 'La fecha ya pasó.';

    public static function getMessageMinMatchesExceed(int $minimum, string $group, int $matches): string
    {
        return "Los partidos mínimos para liguilla ({$minimum}) superan los que jugará cada equipo en el grupo {$group} ({$matches}).";
    }

    public const PLAYOFF_BEST_COUNT = 'Los mejores deben ser menos que los equipos candidatos de esa posición.';

    public const PLAYOFF_BEST_POSITION = 'Esa posición no existe en todos los grupos.';

    public const PLAYOFF_BEST_DUPLICATE = 'Una liguilla no puede tener dos fuentes de mejores de la misma posición.';

    public const PLAYOFF_BEST_NOT_SOURCE = 'La fuente debe ser de mejores de una posición.';

    public const CLOSE_ONLY_IN_PROGRESS = 'Solo se puede cerrar la fase regular de un torneo en curso.';

    public const CLOSE_NO_PLAYOFFS = 'Este torneo no tiene liguilla; usa Terminar torneo.';

    public const CLOSE_PENDING_MATCHES = 'Faltan partidos de la fase regular por jugar.';

    public const CLOSE_UNRESOLVED_TIES = 'Hay empates sin resolver en posiciones de clasificación; define el orden manual en la tabla.';

    public const CLOSE_NO_CALENDAR = 'No hay calendario de fase regular generado.';

    public const CLOSE_INVALID_QUALIFIED = 'La liguilla necesita un número de equipos clasificados potencia de 2.';

    public const CLOSE_BLOCKED = 'No se puede cerrar la fase regular.';

    public const CLOSE_CONFIRM_ALL_PLAYOFFS = 'Confirma las fechas de todas las liguillas.';

    public const CLOSE_TIES_INVALID = 'Los cruces deben usar exactamente a los equipos clasificados.';

    public const CLOSE_DATES_AFTER_REGULAR = 'Las fechas de liguilla deben ser posteriores al último partido de la fase regular.';

    public const PLAYOFFS_NOT_STARTED = 'El torneo aún no está en liguilla.';

    public const PLAYOFFS_PENDING_MATCHES = 'Faltan partidos de liguilla por jugar.';

    public const MATCH_NO_TEAMS = 'Este partido aún no tiene equipos definidos.';

    public const MATCH_FIRST_LEG_FIRST = 'Primero registra el resultado de la ida.';

    public const MATCH_REGULAR_NO_PENALTIES = 'La fase regular no tiene penales.';

    public const MATCH_FIRST_LEG_NO_PENALTIES = 'La ida no tiene penales.';

    public const MATCH_PENALTIES_REQUIRED = 'En caso de empate debes capturar los penales con un ganador.';

    public const MATCH_PENALTIES_ONLY_ON_DRAW = 'Los penales solo se capturan cuando hay empate.';

    public const MATCH_NEXT_ROUND_HAS_RESULTS = 'No se puede modificar: ya hay resultados posteriores.';

    public const MATCH_TOURNAMENT_FINISHED = 'El torneo ya terminó: no se pueden modificar resultados.';

    public const REGULAR_STAGE_CLOSED = 'La fase regular ya está cerrada.';

    public const TOURNAMENT_NOT_STARTED = 'El torneo no ha iniciado.';

    public const MATCH_RESULT_ONLY_REGULAR_STAGE = 'Solo se puede capturar el resultado de partidos de fase regular.';

    public const STANDINGS_GROUP_INVALID = 'El grupo debe ser de fase regular y pertenecer a este torneo.';

    public const STANDINGS_NOT_TIED = 'Esos equipos no están empatados.';

    public const STANDINGS_MANUAL_ORDER_ONLY_IN_PROGRESS = 'El orden manual solo se puede modificar con el torneo en curso.';

    public const GROUPS_TOURNAMENT_PLAYOFFS = 'Con el torneo en liguilla no se pueden modificar los grupos.';

    public const PLAYOFFS_LOCKED = 'Las liguillas ya no se pueden modificar.';

    public const PLAYOFF_HAS_MATCHES = 'Esta liguilla ya tiene partidos y no se puede modificar.';

    public const TOURNAMENT_TO_PLAYOFFS_USE_CLOSE = 'Para pasar a liguilla usa Cerrar fase regular.';

    public const TOURNAMENT_FINISH_HAS_PLAYOFFS = 'El torneo tiene liguillas: usa Cerrar fase regular para pasar a liguilla antes de finalizar.';

    public const ROSTER_REJOIN_TOURNAMENT_CLOSED = 'Con el torneo en liguilla o finalizado no se pueden autorizar reingresos.';

    public const PLAYOFF_ROUND_DATES_NEED_START = 'Para indicar fechas por ronda primero define la fecha de inicio.';

    public const PLAYOFF_ROUND_DATES_FIRST_START = 'La primera fecha de las rondas debe ser igual a la fecha de inicio.';

    public const GROUP_NAME_TAKEN = 'Ya hay un grupo con ese nombre en este torneo.';

    public const GROUP_DELETE_LAST_REGULAR = 'No se puede eliminar el último grupo de fase regular del torneo.';

    public const GROUP_DELETE_HAS_TEAMS = 'El grupo tiene equipos asignados. Muévelos a otro grupo primero.';

    public const GROUP_DELETE_HAS_MATCHES = 'El grupo tiene partidos y no se puede eliminar.';

    public const GROUP_DELETE_USED_BY_PLAYOFF = 'El grupo es fuente de una liguilla. Quítalo de la liguilla primero.';

    public const GROUP_RANDOM_NEEDS_TEAMS = 'Se necesitan al menos 2 equipos inscritos para repartirlos en grupos.';

    public const GROUP_ASSIGN_INVALID_REGISTRATION = 'La inscripción debe estar activa y pertenecer a este torneo.';

    public const GROUP_ASSIGN_INVALID_GROUP = 'El grupo debe ser de fase regular y pertenecer a este torneo.';

    public const PLAYOFF_SOURCE_INVALID_GROUP = 'El grupo de origen debe ser un grupo de fase regular de este torneo.';

    public static function getMessagePlayoffTotalTeams(int $total): string
    {
        return "El total de equipos de la liguilla debe ser 2, 4, 8, 16 o 32 (actualmente {$total}).";
    }

    public const PLAYOFF_ROUND_DATES_COUNT = 'Indica una fecha por cada partido de la liguilla.';

    public const PLAYOFF_ROUND_LEGS_COUNT = 'Indica cuántos partidos tiene cada ronda.';

    public const PLAYOFF_ROUND_LEGS_VALUE = 'Cada ronda debe ser de un partido o de ida y vuelta.';

    public const PLAYOFF_ROUND_DATES_ORDER = 'Las fechas de las rondas deben ir en orden.';

    public const PLAYOFF_BEST_OTHER_PLAYOFF = 'Los candidatos de esa posición ya pertenecen a otra liguilla.';

    public static function getMessagePlayoffOverlap(string $group): string
    {
        return "Las posiciones de {$group} se traslapan con otra fuente de liguilla.";
    }

    public static function getMessageGroupNeedsTeams(string $group): string
    {
        return "El grupo {$group} necesita al menos 2 equipos.";
    }

    public static function getMessagePlayoffAsksTooMany(string $playoff, int $position, string $group, int $teams): string
    {
        return "La liguilla {$playoff} pide hasta la posición {$position} del grupo {$group}, que solo tiene {$teams} equipos.";
    }

    public const ROSTER_CLOSED = 'Las plantillas de este torneo están cerradas.';

    public const ROSTER_FULL = 'El equipo ya alcanzó el máximo de jugadores del torneo.';

    public const ROSTER_REQUEST_DUPLICATE = 'El jugador ya tiene una solicitud abierta con este equipo.';

    public const ROSTER_JERSEY_TAKEN = 'Ese dorsal ya está en uso en el equipo.';

    /** Un solo mensaje para veto, baja sin autorización o jugador ya activo en otro equipo: no revela cuál. */
    public const ROSTER_PLAYER_UNAVAILABLE = 'No se puede enviar la solicitud a este jugador.';

    public const ROSTER_NOT_PENDING = 'La solicitud ya no está pendiente.';

    public const ROSTER_REMOVE_ONLY_ACTIVE = 'Solo se puede quitar a un jugador activo.';

    public const ROSTER_REMOVE_TOURNAMENT_FINISHED = 'Solo se puede quitar a un jugador con el torneo en planeación o en curso.';

    public const ROSTER_REMOVAL_REASON_REQUIRED = 'El motivo es obligatorio.';

    public const ROSTER_REMOVE_PLAYOFFS_STARTED = 'La liguilla ya inició: no se puede quitar a un jugador de la plantilla.';

    public const ROSTER_REJOIN_NOT_AUTHORIZED = 'Este jugador no tiene un reingreso autorizado.';

    public const ROSTER_REJOIN_ALREADY_USED = 'El jugador ya tiene una solicitud nueva en este torneo: no se puede revocar el reingreso.';

    public const ROSTER_REJOIN_ONLY_REMOVED = 'Solo se puede autorizar a un jugador que fue quitado.';

    public static function getMessageMaxRosterBelowCurrent(int $max, string $team, int $players): string
    {
        return "No se puede fijar el máximo en {$max}: el equipo «{$team}» ya tiene {$players} jugadores (activos y pendientes).";
    }

    public static function getMessageInvalidTournamentTransition(string $from, string $to): string
    {
        return "No se puede pasar el torneo de «{$from}» a «{$to}». Solo se permite avanzar: planeación → en curso → liguilla → finalizado.";
    }

    public static function getMessageLeagueHasActiveTournaments(string $leagueName): string
    {
        $name = e($leagueName);

        return "La liga <b>{$name}</b> tiene torneos activos asociados. Para eliminarla, primero debes finalizar o eliminar esos torneos.";
    }

    public static function getMessageFieldHasScheduledMatches(string $fieldName): string
    {
        $name = e($fieldName);

        return "La cancha <b>{$name}</b> tiene partidos programados. Reasígnalos a otra cancha o quítales el horario antes de eliminarla.";
    }

    public static function getMessageFieldDeactivateHasScheduledMatches(string $fieldName): string
    {
        $name = e($fieldName);

        return "La cancha <b>{$name}</b> tiene partidos programados. Reasígnalos a otra cancha o quítales el horario antes de darla de baja.";
    }

    public static function getMessageFieldInOpenScheduleConfig(string $fieldName): string
    {
        $name = e($fieldName);

        return "La cancha <b>{$name}</b> está en la configuración de horarios de un torneo que no ha terminado. Quítala de esa configuración antes de eliminarla.";
    }

    public static function getMessageRefereeHasAssignedMatches(string $refereeName): string
    {
        $name = e($refereeName);

        return "El árbitro <b>{$name}</b> tiene partidos asignados en esta liga. Reasígnalos a otro árbitro antes de quitarlo.";
    }

    public static function getMessageRefereeDeactivateHasAssignedMatches(string $refereeName): string
    {
        $name = e($refereeName);

        return "El árbitro <b>{$name}</b> tiene partidos asignados en esta liga. Reasígnalos a otro árbitro antes de darlo de baja.";
    }

    public static function getMessageUserAdministersLeagues(int $leagues, string $action): string
    {
        $subject = $leagues === 1 ? 'una liga' : "{$leagues} ligas";

        return "No se puede {$action} porque administra {$subject}. Reasigne primero su encargado.";
    }

    public static function getMessageUserManagesTeams(int $teams, string $action): string
    {
        $subject = $teams === 1 ? 'un equipo' : "{$teams} equipos";

        return "No se puede {$action} porque es delegado de {$subject}. Reasigne primero el delegado.";
    }

    public static function getMessageHasNotAllowedSorts(string $class): string
    {
        return "Establezca la propiedad pública allowedSorts dentro de $class";
    }

    public static function getMessageHasNotAllowedFilters(string $class): string
    {
        return "Establezca la propiedad pública allowedFilters dentro de $class";
    }
}
