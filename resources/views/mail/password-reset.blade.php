<x-mail::message>
# Reset your {{ config('app.name') }} password

Someone asked to reset the password for this account. Click the button below to choose a new one.
<br>
<a href="{{ $url }}">
  <button style="background-color: #3490dc; color: white; padding: 10px 20px; border-radius: 5px; border: none;">
    Reset Password
  </button>
</a>

If you are unable to click the button, copy and paste the following URL into your browser:
<br>
{{ $url }}
<br>
<br>

This link expires in one hour and can only be used once.

If you did not request this, you can ignore this email; your password will not change.

</x-mail::message>
