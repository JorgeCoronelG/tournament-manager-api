<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * «Hoy» de todas las pruebas. Los escenarios usan fechas fijas (partidos en noviembre, liguillas en diciembre):
     * con el reloj real, una prueba que hoy pasa dejaría de hacerlo cuando esas fechas queden atrás o caigan en la
     * semana actual. Una prueba que necesite otro día puede llamar a travelTo(); el reloj se restablece al terminar.
     */
    protected const FIXED_NOW = '2026-10-26 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::FIXED_NOW);
    }
}
