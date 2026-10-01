<x-public-layout title="Sign in">
    <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">Sign in to approve access for an external application.</p>
    @if (session('status'))<p class="mb-4 text-sm">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('login') }}" class="space-y-5">
        @csrf
        <div><label class="block text-sm font-medium" for="email">Email</label><input class="mt-1 w-full rounded border border-gray-300 p-2 dark:bg-gray-800" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>@error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <div><label class="block text-sm font-medium" for="password">Password</label><input class="mt-1 w-full rounded border border-gray-300 p-2 dark:bg-gray-800" id="password" name="password" type="password" autocomplete="current-password" required>@error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <label class="flex items-center gap-2 text-sm"><input name="remember" type="checkbox" value="1">Remember me</label>
        <button class="rounded bg-blue-600 px-4 py-2 font-medium text-white" type="submit">Sign in</button>
    </form>
    <a class="mt-5 inline-block text-sm underline" href="{{ route('password.request') }}">Forgot password?</a>
</x-public-layout>
