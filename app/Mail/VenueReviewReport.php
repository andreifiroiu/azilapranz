<?php

namespace App\Mail;

use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Sent by azp:places:refresh when venues are newly flagged.
 *
 * Only newly-changed venues appear, so an empty inbox means nothing changed —
 * not that the check stopped running.
 */
class VenueReviewReport extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, Location>  $venues */
    public function __construct(public Collection $venues) {}

    public function envelope(): Envelope
    {
        $closed = $this->venues
            ->where('place_id_status', GooglePlaces::CLOSED_PERMANENTLY)
            ->count();

        // The permanently-closed count goes in the subject because it is the
        // one number that decides whether this is worth opening now. Phrased
        // count-last to sidestep Romanian's three-way plural (local /
        // localuri / de localuri) in a string nobody will maintain.
        $subject = $closed > 0
            ? sprintf('[%s] Localuri închise definitiv: %d', config('azp.site_name'), $closed)
            : sprintf('[%s] Localuri de verificat: %d', config('azp.site_name'), $this->venues->count());

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.venue-review-report');
    }
}
