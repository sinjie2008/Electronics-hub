<x-public-layout title="Authorize application">
    <p><strong>{{ $client->name }}</strong> requests access to your account.</p>
    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Signed in as {{ $user->email }}.</p>
    <ul class="my-6 list-disc space-y-2 pl-5">@forelse ($scopes as $scope)<li>{{ $scope->description }}</li>@empty<li>Authenticate with this application.</li>@endforelse</ul>
    <div class="flex gap-4">
        <form method="post" action="{{ route('passport.authorizations.approve') }}">@csrf<input name="auth_token" type="hidden" value="{{ $authToken }}"><button class="rounded bg-blue-600 px-4 py-2 font-medium text-white" type="submit">Authorize</button></form>
        <form method="post" action="{{ route('passport.authorizations.deny') }}">@csrf @method('DELETE')<input name="auth_token" type="hidden" value="{{ $authToken }}"><button class="rounded border border-gray-400 px-4 py-2" type="submit">Deny</button></form>
    </div>
</x-public-layout>
