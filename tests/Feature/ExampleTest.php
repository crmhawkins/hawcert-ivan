<?php

test('el panel exige iniciar sesión', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
