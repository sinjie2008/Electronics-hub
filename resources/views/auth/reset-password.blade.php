<x-public-layout title="Choose a new password">
    <form method="post" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input name="token" type="hidden" value="{{ $token }}">
        <div><label class="block text-sm font-medium" for="email">Email</label><input class="mt-1 w-full rounded border border-gray-300 p-2 dark:bg-gray-800" id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required>@error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <div><label class="block text-sm font-medium" for="password">New password</label><input class="mt-1 w-full rounded border border-gray-300 p-2 dark:bg-gray-800" id="password" name="password" type="password" autocomplete="new-password" required>@error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <div><label class="block text-sm font-medium" for="password_confirmation">Confirm password</label><input class="mt-1 w-full rounded border border-gray-300 p-2 dark:bg-gray-800" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
        <button class="rounded bg-blue-600 px-4 py-2 text-white" type="submit">Reset password</button>
    </form>
</x-public-layout>
