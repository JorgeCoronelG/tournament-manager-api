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

    public static function getMessagePlayerGenderLocked(string $tournaments): string
    {
        return "No se puede cambiar la rama porque el jugador está inscrito en: {$tournaments}. Primero da de baja al jugador de esos torneos.";
    }

    public const TOURNAMENT_PLAYOFFS_STARTED_LOCKED = 'La liguilla ya inició: no se pueden modificar el máximo de jugadores ni los partidos mínimos.';

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
        return "No se puede pasar el torneo de «{$from}» a «{$to}». Solo se permite avanzar: planeación → en curso → finalizado.";
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
