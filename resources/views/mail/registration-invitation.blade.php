<x-mail::message>
# You have been invited to join {{ env('APP_NAME') }}!

You have been invited to join a {{ env('APP_NAME') }} instance. Click the button below to sign up.
<br>
<a href="{{ $url }}">
  <button style="background-color: #3490dc; color: white; padding: 10px 20px; border-radius: 5px; border: none;">
    Sign Up
  </button>
</a>

If you are unable to click the button, copy and paste the following URL into your browser:
<br>
{{ $url }}
<br>
<br>

If you did not request this, please ignore this email.

</x-mail::message>
