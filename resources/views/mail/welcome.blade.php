<x-mail::message>
<img src="{{ url('/images/welcome.jpg') }}" alt="Welcome to Animal Farm: the revolution begins. Join us, player!" width="570" style="display: block; width: 100%; max-width: 570px; height: auto; border-radius: 8px; margin: 0 0 24px;">

# Welcome to {{ $valley }}, {{ $name }}

The humans have left the farm and the animals run it now. You're a **{{ $species }}**: {{ lcfirst($talent) }}

Here's how to get going:

- **Spend your horseshoes.** You have {{ $horseshoes }}. Every action costs one or more, one comes back every 45 minutes, and every 24 hours you're filled back up to {{ $horseshoes }}.
- **Walk the map.** Tap anywhere on the map to walk there, gather Corn and materials, and find water and food before winter.
- **Join a faction, or go it alone.** Factions hold land and fight for the Farmhouse. Whoever holds it writes the laws on the Barn Wall.
- **Read the Wall.** Once a week the rulers may secretly change a law. Spot it within 72 hours for fame and Rosettes.

## Earn while you play

The farm runs on Apples, and you can earn them:

- **Design and sell.** Draw hats, scarves and signs in the Workshop and sell numbered copies from your own shop for Apples.
- **Hunt outlaws.** Catch an animal with a bounty on its head and the bounty's Apples are yours.
- **Get paid out.** Later in the game, Apples you earned can be paid out to your own PayPal account.

<x-mail::button :url="route('play')">
Go to the farm
</x-mail::button>

Want the whole picture? Read [how the game works]({{ route('wiki') }}).

See you in the valley,<br>
The Animal Farm Dev Team
</x-mail::message>
