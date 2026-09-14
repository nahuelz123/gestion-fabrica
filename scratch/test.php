<?php
$u = App\Models\User::where('email', 'admin@fabrica.com')->first();
if ($u) {
    echo 'Usuario encontrado. Rol: ' . $u->role->value . PHP_EOL;
    echo 'Password coincide: ' . (Illuminate\Support\Facades\Hash::check('password', $u->password) ? 'SI' : 'NO') . PHP_EOL;
} else {
    echo 'Usuario NO encontrado' . PHP_EOL;
}
