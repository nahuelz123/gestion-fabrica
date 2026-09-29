<?php

namespace App\Services;

use App\Models\User;

class AuthorizedBotActionExecutor extends BulkBotActionExecutor
{
    public function execute(User $user, string $chatId, array $action, array $context): array
    {
        $name = $action['name'] ?? null;
        if (!$user->canUseBotAction(is_string($name) ? $name : null)) {
            return [
                'success' => false,
                'message' => $user->isManager()
                    ? 'Tu rol de encargado sólo permite consultar y gestionar stock.'
                    : 'No tenés permiso para realizar esa operación.',
            ];
        }
        return parent::execute($user, $chatId, $action, $context);
    }
}
