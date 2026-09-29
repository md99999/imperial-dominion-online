# Testing league play on two local sites

League play needs two WordPress sites that can call each other, which makes it the one part of this
game that cannot be tested on a single install. Two Local (Flywheel) sites on one machine is the
cheapest way to do it, and it takes one deliberate concession to get there.

## The concession, and its limits

Every peer address has to be HTTPS at a public address. Two Local sites are
`https://something.local` on a private address, so the address half of that rule has to be lifted
for local testing. **The transport half is not lifted.** HTTPS is still required, because Local can
issue a trusted certificate per site and a test that ran over plain http would be exercising a code
path that does not exist in production.

Two things must both be true for the allowance to apply:

1. `IDO_LEAGUE_ALLOW_PRIVATE_HOSTS` is defined true in `wp-config.php`. A constant in a file, not a
   setting, so it cannot be switched on through the admin screens.
2. WordPress reports the site as `local` or `development` through `wp_get_environment_type()`.

The second condition is the one doing the work. A constant alone would eventually be copied into a
production `wp-config.php` along with everything else in that file, and the whole defence against
server-side request forgery would be gone without anybody noticing. Requiring the environment to
agree means a copied constant is inert: it is read, and ignored.

Both admin screens carry a red notice while it is on, naming the constant and the environment that
allowed it.

## Setting the two sites up

1. **Create a second Local site.** Any name; these instructions assume `imperial-a` and
   `imperial-b`, reachable at `https://imperial-a.local` and `https://imperial-b.local`.

2. **Turn on trusted SSL for both.** In Local, open the site, go to the **SSL** tab and press
   **Trust**. Local installs the certificate into the Windows trust store, and
   `https://imperial-a.local` then loads without a warning. Do this for both sites: a peer with an
   untrusted certificate will be refused when the packet code lands, exactly as it would be in
   production.

3. **Check WordPress agrees the site is local.** Local usually sets this already; if not, add to
   `wp-config.php`:

       define( 'WP_ENVIRONMENT_TYPE', 'local' );

4. **Add the allowance to both sites' `wp-config.php`**, above the `/* That's all, stop editing */`
   line:

       define( 'IDO_LEAGUE_ALLOW_PRIVATE_HOSTS', true );

5. **Confirm each site's own address is usable.** The League screen refuses to found or join a
   league when it is not, and says so. If it complains, the site's WordPress Address in
   **Settings → General** is still `http://`, which Local's Trust step does not change on its own.

6. **Install the plugin on both**, enable league play on both, then found a league on A and join it
   from B with an invitation A generates.

## What is testable today, and what is not

Built: opting in, founding, invitations, recording an enrolment, the endpoint gate, leaving, and the
kill switch. Two sites are enough to exercise all of it, including that B refuses an invitation
pointing at an address it will not call.

Not built yet: the handshake that turns a pending enrolment into a member, the packet endpoint, and
everything downstream of it, which is where a second site starts earning its keep. The enrolment
will sit at `pending` until the handshake exists, and the League screen says so rather than implying
more than is there.

## When you are finished

Remove `IDO_LEAGUE_ALLOW_PRIVATE_HOSTS` from any `wp-config.php` that might ever be copied to a real
site. The environment check is a safety net, not a reason to leave it lying around.
