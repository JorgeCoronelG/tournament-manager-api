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

    public static function getMessageInvalidTournamentTransition(string $from, string $to): string
    {
        return "No se puede pasar el torneo de «{$from}» a «{$to}». Solo se permite avanzar: planeación → en curso → finalizado.";
    }

    public static function getMessageUserAdministersLeagues(int $leagues, string $action): string
    {
        $subject = $leagues === 1 ? 'una liga' : "{$leagues} ligas";

        return "No se puede {$action} porque administra {$subject}. Reasigne primero su encargado.";
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
