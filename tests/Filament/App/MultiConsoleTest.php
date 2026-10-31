<?php

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Filament\App\Resources\Servers\Pages\MultiConsole;
use App\Filament\Server\Widgets\ServerConsole;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('app');

    Http::fake(['*' => Http::response(['state' => 'running', 'utilization' => []])]);
});

it('only opens consoles for servers the user may connect to', function () {
    [$user, $own] = generateTestAccount();
    $foreign = createServerModel();
    [, $withoutWebsocket] = generateTestAccount([SubuserPermission::ControlConsole]);
    $withoutWebsocket->subusers()->update(['user_id' => $user->id]);

    $this->actingAs($user);

    $component = livewire(MultiConsole::class, [
        'servers' => implode(',', [$foreign->uuid, $own->uuid, 'not-a-uuid', $withoutWebsocket->uuid]),
    ])->assertSuccessful();

    expect($component->instance()->getConsoleServers()->pluck('uuid')->all())->toBe([$own->uuid]);
});

it('keeps the chosen order and drops duplicates', function () {
    [$user, $first] = generateTestAccount();
    $second = createServerModel(['owner_id' => $user->id]);

    $this->actingAs($user);

    $component = livewire(MultiConsole::class, [
        'servers' => implode(',', [$second->uuid, $first->uuid, $second->uuid]),
    ]);

    expect($component->instance()->getConsoleServers()->pluck('uuid')->all())->toBe([$second->uuid, $first->uuid]);
});

it('caps the number of consoles', function () {
    [$user] = generateTestAccount();
    $uuids = collect(range(1, MultiConsole::MAX_SERVERS + 2))
        ->map(fn () => createServerModel(['owner_id' => $user->id])->uuid);

    $this->actingAs($user);

    $component = livewire(MultiConsole::class, ['servers' => $uuids->implode(',')]);

    expect($component->instance()->getConsoleServers())->toHaveCount(MultiConsole::MAX_SERVERS);
});

it('renders a compact console for each chosen server', function () {
    [$user, $first] = generateTestAccount();
    $second = createServerModel(['owner_id' => $user->id]);

    $this->actingAs($user);

    livewire(MultiConsole::class, ['servers' => "$first->uuid,$second->uuid"])
        ->assertSuccessful()
        ->assertSeeLivewire(ServerConsole::class)
        ->assertSee([$first->name, $second->name]);
});

it('remembers the last selection when opened without one', function () {
    [$user, $server] = generateTestAccount();

    $this->actingAs($user);

    livewire(MultiConsole::class)
        ->callAction('choose_servers', ['servers' => [$server->uuid], 'columns' => 3])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    livewire(MultiConsole::class)
        ->assertSet('servers', $server->uuid)
        ->assertSet('columns', 3);
});

it('falls back to two columns for an unsupported column count', function () {
    [$user] = generateTestAccount();

    $this->actingAs($user);

    livewire(MultiConsole::class, ['columns' => 9])
        ->assertSet('columns', 2);
});

it('ignores another server\'s console status updates', function () {
    [$user, $server] = generateTestAccount();
    $other = createServerModel(['owner_id' => $user->id]);

    $this->actingAs($user);

    livewire(ServerConsole::class, ['server' => $server, 'user' => $user, 'compact' => true])
        ->dispatch('console-status', state: 'offline', uuid: $other->uuid);

    // Rendering caches the daemon's 'running' state; another server's 'offline' must not replace it.
    expect(cache()->get("servers.$server->uuid.status"))->toBe(ContainerStatus::Running);

    livewire(ServerConsole::class, ['server' => $server, 'user' => $user, 'compact' => true])
        ->dispatch('console-status', state: 'offline', uuid: $server->uuid);

    expect(cache()->get("servers.$server->uuid.status"))->toBe(ContainerStatus::Offline);
});

it('saves a dragged order, ignoring servers that are not open', function () {
    [$user, $first] = generateTestAccount();
    $second = createServerModel(['owner_id' => $user->id]);
    $third = createServerModel(['owner_id' => $user->id]);
    $foreign = createServerModel();

    $this->actingAs($user);

    livewire(MultiConsole::class, ['servers' => "$first->uuid,$second->uuid,$third->uuid"])
        // The third server is missing from the new order, so it stays at the end.
        ->call('reorder', [$second->uuid, $foreign->uuid, 'junk', $first->uuid])
        ->assertSet('servers', "$second->uuid,$first->uuid,$third->uuid");

    expect(session('multi_console.servers'))->toBe("$second->uuid,$first->uuid,$third->uuid");
});
