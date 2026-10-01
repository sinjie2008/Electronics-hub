<x-filament-panels::page>
    <dl class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($information as $label => $value)
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="mt-1 break-words text-sm text-gray-950 dark:text-white">
                    @if (is_array($value))
                        {{ $value === [] ? 'None' : implode(', ', $value) }}
                    @else
                        {{ $value }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</x-filament-panels::page>
