<x-public-layout title="Application ready">
    <p>This application uses session authentication for its administration panel and OAuth2 for integrations.</p>
    <a class="mt-6 inline-block font-medium text-blue-600 underline" href="{{ route('filament.admin.auth.login') }}">Administration</a>
    @auth
        <form class="mt-6" method="post" action="{{ route('logout') }}">@csrf<button class="text-sm underline" type="submit">Sign out</button></form>
    @else
        <a class="ml-4 text-blue-600 underline" href="{{ route('login') }}">Sign in</a>
    @endauth
</x-public-layout>
