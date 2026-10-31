<?php

namespace App\Filament\App\Resources\Servers\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Filament\App\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;

class MultiConsole extends Page
{
    // Each console holds a websocket and a WebGL context, and browsers cap the
    // latter at about 16, so keep well under that.
    public const MAX_SERVERS = 12;

    public const COLUMNS = [1, 2, 3, 4];

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.app.pages.multi-console';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** Comma-separated server UUIDs, so a layout can be bookmarked. */
    #[Url]
    public string $servers = '';

    #[Url]
    public int $columns = 2;

    public function mount(): void
    {
        // Opening the page without a selection brings back the last one.
        if ($this->servers === '') {
            $this->servers = (string) session('multi_console.servers', '');
            $this->columns = (int) session('multi_console.columns', $this->columns);
        }

        if (!in_array($this->columns, self::COLUMNS, true)) {
            $this->columns = 2;
        }
    }

    /**
     * The chosen servers the user may open a console for, in the chosen order.
     * The UUIDs come from the query string, so never trust them as given.
     *
     * @return Collection<int, Server>
     */
    public function getConsoleServers(): Collection
    {
        $uuids = collect(explode(',', $this->servers))
            ->map(fn (string $uuid) => trim($uuid))
            ->filter()
            ->unique()
            ->take(self::MAX_SERVERS)
            ->values();

        if ($uuids->isEmpty()) {
            return collect();
        }

        $servers = $this->authorizedServers()->whereIn('uuid', $uuids)->keyBy('uuid');

        return $uuids
            ->map(fn (string $uuid) => $servers->get($uuid))
            ->filter()
            ->values();
    }

    /** @return Collection<int, Server> */
    protected function authorizedServers(): Collection
    {
        $user = user();

        if (!$user) {
            return collect();
        }

        /** @var Collection<int, Server> $servers */
        $servers = $user->accessibleServers()
            ->with('node')
            ->orderBy('servers.name')
            ->get();

        return $servers
            ->filter(fn (Server $server) => $user->can(SubuserPermission::WebsocketConnect, $server))
            ->values();
    }

    /**
     * Saves the order the panes were dragged into. The browser has already moved
     * them, so skip the render; only reorder servers that are already open.
     *
     * @param  string[]  $uuids
     */
    #[Renderless]
    public function reorder(array $uuids): void
    {
        $current = $this->getConsoleServers()->pluck('uuid');

        $this->servers = collect($uuids)
            ->filter(fn ($uuid) => is_string($uuid) && $current->contains($uuid))
            ->unique()
            ->merge($current)
            ->unique()
            ->implode(',');

        session()->put('multi_console.servers', $this->servers);
    }

    public function getGridRows(int $count): int
    {
        return max(1, (int) ceil($count / max(1, $this->columns)));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->chooseServersAction(),
        ];
    }

    public function chooseServersAction(): Action
    {
        return Action::make('choose_servers')
            ->label(trans('server/dashboard.multi_console.choose'))
            ->icon(TablerIcon::LayoutGrid)
            ->modalSubmitActionLabel(trans('server/dashboard.multi_console.submit'))
            ->fillForm(fn () => [
                'servers' => $this->getConsoleServers()->pluck('uuid')->all(),
                'columns' => $this->columns,
            ])
            ->schema([
                CheckboxList::make('servers')
                    ->label(trans('server/dashboard.multi_console.servers'))
                    ->helperText(trans('server/dashboard.multi_console.servers_helper', ['max' => self::MAX_SERVERS]))
                    ->options(fn () => $this->authorizedServers()
                        ->mapWithKeys(fn (Server $server) => [$server->uuid => "$server->name ({$server->node->name})"])
                        ->all())
                    ->searchable()
                    ->bulkToggleable()
                    ->columns(2)
                    ->required()
                    ->maxItems(self::MAX_SERVERS),
                ToggleButtons::make('columns')
                    ->label(trans('server/dashboard.multi_console.columns'))
                    ->options(collect(self::COLUMNS)->mapWithKeys(fn (int $columns) => [$columns => (string) $columns])->all())
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $data) {
                $servers = implode(',', $data['servers']);
                $columns = (int) $data['columns'];

                session()->put('multi_console.servers', $servers);
                session()->put('multi_console.columns', $columns);

                // A full reload, so the consoles being replaced close their sockets.
                $this->redirect(static::getUrl(['servers' => $servers, 'columns' => $columns]));
            });
    }

    public function getTitle(): string
    {
        return trans('server/dashboard.multi_console.title');
    }
}
