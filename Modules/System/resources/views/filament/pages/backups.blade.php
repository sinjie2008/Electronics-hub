<x-filament-panels::page>
    <div wire:poll.15s>
        <x-filament::section heading="Backup health" description="Downloads require separate permission. Keep archives private and restore using your deployment procedure.">
            @foreach ($destinations as $destination)
                <p><strong>{{ $destination['disk'] }}</strong>: {{ $destination['reachable'] ? 'Accessible' : 'Unavailable' }} · {{ $destination['healthy'] ? 'Healthy' : 'Needs attention (age, size, or availability)' }}</p>
            @endforeach
        </x-filament::section>
        <x-filament::section heading="Backup archives">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b"><th class="p-3">Archive</th><th class="p-3">Date</th><th class="p-3">Size</th><th class="p-3">Disk</th><th class="p-3">Actions</th></tr></thead>
                    <tbody>
                        @forelse ($archives as $archive)
                            <tr class="border-b"><td class="p-3">{{ $archive['filename'] }}</td><td class="p-3">{{ $archive['date']->toDateTimeString() }}</td><td class="p-3">{{ number_format($archive['size'] / 1024 / 1024, 2) }} MB</td><td class="p-3">{{ $archive['disk'] }}</td><td class="p-3">@can('backups.download')<x-filament::button size="sm" tag="a" :href="route('system.backups.download', ['disk' => $archive['disk'], 'filename' => $archive['filename']])">Download</x-filament::button>@endcan</td></tr>
                        @empty
                            <tr><td class="p-3" colspan="5">No backup archives yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
        <x-filament::section heading="Recent backup jobs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b"><th class="p-3">Requested</th><th class="p-3">Type</th><th class="p-3">Status</th><th class="p-3">Finished</th></tr></thead>
                    <tbody>@forelse ($runs as $run)<tr class="border-b"><td class="p-3">{{ $run->created_at->toDateTimeString() }}</td><td class="p-3">{{ ucfirst($run->type) }}</td><td class="p-3">{{ ucfirst($run->status) }}@if($run->failure_message)<p>{{ $run->failure_message }}</p>@endif</td><td class="p-3">{{ $run->finished_at?->toDateTimeString() ?? 'Pending' }}</td></tr>@empty<tr><td class="p-3" colspan="4">No backup jobs yet.</td></tr>@endforelse</tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
