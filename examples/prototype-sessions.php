<?php

declare(strict_types=1);

/**
 * PROTOTIPO USA-E-GETTA — non è codice di produzione.
 *
 * Domanda a cui risponde: con i meccanismi che NeuronAI ha già, quanto costa
 * elencare le conversazioni, riaprirne una e crearne una nuova?
 *
 * Si esegue con:  php examples/prototype-sessions.php
 * Scrive in:      examples/.prototype-sessions-WIPE-ME/
 */

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

require_once __DIR__ . '/vendor/autoload.php';

$directory = __DIR__ . '/.prototype-sessions-WIPE-ME';

// Pulizia, così ogni esecuzione riparte da zero.
foreach (glob($directory . '/*') ?: [] as $file) {
    unlink($file);
}

/**
 * ─────────────────────────────────────────────────────────────────────────
 * L'UNICO CODICE CHE NEURON NON CI DÀ.
 *
 * Elencare le conversazioni presenti. Il resto — salvare, ricaricare,
 * deserializzare tool call comprese — lo fa FileChatHistory da sé.
 * ─────────────────────────────────────────────────────────────────────────
 */
function listSessions(string $directory): array
{
    $sessions = [];

    foreach (glob($directory . '/neuron_*.chat') ?: [] as $path) {
        $key = substr(basename($path), strlen('neuron_'), -strlen('.chat'));

        // Riapriamo con Neuron: niente parsing JSON fatto a mano.
        $history = new FileChatHistory($directory, $key);
        $title = '(vuota)';

        foreach ($history->getMessages() as $message) {
            if ($message->getRole() === MessageRole::USER->value) {
                $title = (string) $message->getContent();
                break;
            }
        }

        $sessions[] = [
            'key' => $key,
            'lastUsedAt' => date('H:i:s', filemtime($path) ?: 0),
            'title' => mb_strimwidth($title, 0, 40, '…'),
            'messages' => count($history->getMessages()),
        ];
    }

    usort($sessions, fn (array $a, array $b): int => $b['lastUsedAt'] <=> $a['lastUsedAt']);

    return $sessions;
}

function show(string $step, string $directory): void
{
    echo "\n\033[35m─── {$step}\033[0m\n";
    $sessions = listSessions($directory);

    if ($sessions === []) {
        echo "  (nessuna sessione)\n";

        return;
    }

    foreach ($sessions as $session) {
        printf(
            "  %-14s  %s  %2d msg  %s\n",
            $session['key'],
            $session['lastUsedAt'],
            $session['messages'],
            $session['title'],
        );
    }
}

// ── 1. Si parte senza niente ────────────────────────────────────────────
show('1. All\'avvio', $directory);

// ── 2. /clear apre una sessione nuova: una chiave nuova, nient'altro ────
$agent = new Agent();
$first = uniqid();
$agent->setChatHistory(new FileChatHistory($directory, $first));

$agent->getChatHistory()->addMessage(new UserMessage('Come si configura un toolkit?'));
$agent->getChatHistory()->addMessage(new AssistantMessage('Con addTool() sull\'Agent.'));

show('2. Dopo due messaggi nella prima sessione', $directory);

// ── 3. /clear di nuovo: la prima resta sul disco ────────────────────────
sleep(1); // solo per rendere visibile l'ordine per data
$second = uniqid();
$agent->setChatHistory(new FileChatHistory($directory, $second));
$agent->getChatHistory()->addMessage(new UserMessage('Quanto costa un embedding?'));

show('3. Dopo /clear e un messaggio nella seconda', $directory);

// ── 4. /sessions: scelgo la prima e la riapro ───────────────────────────
$chosen = listSessions($directory)[1]['key'];
$agent->setChatHistory(new FileChatHistory($directory, $chosen));

echo "\n\033[35m─── 4. Riaperta la sessione {$chosen}\033[0m\n";

foreach ($agent->getChatHistory()->getMessages() as $message) {
    printf("  %-9s %s\n", $message->getRole(), $message->getContent());
}

// ── 5. E la conversazione riprende da dove era ──────────────────────────
$agent->getChatHistory()->addMessage(new UserMessage('E per rimuoverlo?'));

show('5. Dopo aver ripreso a scrivere nella prima', $directory);

// ── 6. Attenzione: flushAll() non archivia, cancella ────────────────────
$agent->getChatHistory()->flushAll();

show('6. Dopo flushAll() sulla sessione corrente', $directory);

echo "\n\033[2m";
echo "Righe scritte da noi:    listSessions(), ~20 righe di glob e ordinamento\n";
echo "Righe scritte da Neuron: salvataggio, ricarica, deserializzazione, chiavi\n";
echo "\033[0m\n";
