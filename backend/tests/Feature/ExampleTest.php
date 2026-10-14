<?php

test('the home page sends visitors to the login page', function () {
    $this->get('/')->assertRedirect('/login.html');
});
