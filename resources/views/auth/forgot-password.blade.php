<x-public-layout title="Reset password">
    <p class="mb-6 text-sm">Enter your email to request a password reset link.</p>
    @if (session('status'))<p class="mb-4 text-sm">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <div><label class="block text-sm font-medium" for="email">Email</label><input class="mt-1 w-full rounded border border-gray-300 p-2 dark:bg-gray-800" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>@error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <button class="rounded bg-blue-600 px-4 py-2 text-white" type="submit">Send reset link</button>
    </form>
</x-public-layout>
