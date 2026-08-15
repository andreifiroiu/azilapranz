<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImageCompatController extends Controller
{
    /**
     * The legacy resized images on the fly through two entry points:
     *
     *   /thumb.php?image=/userfiles/locations/x.jpg&width=150&height=150
     *   /thumbs/{w}/{h}[/{cropX}/{cropY}]/userfiles/locations/x.jpg
     *
     * Image URLs accrue their own search traffic and external embeds, so both
     * forms 301 to the stored original rather than 404. No resizing: the source
     * files are small and there are only ~500 of them.
     */
    public function thumbPhp(Request $request): Response
    {
        $image = $request->query('image', '');

        // ?image[]=x hands us an array, and casting that to string raises an
        // ErrorException — a 500 where a 404 was intended.
        if (! is_string($image)) {
            abort(404);
        }

        return $this->redirectToAsset($image);
    }

    public function thumbs(string $path): Response
    {
        // Strip the leading {width}/{height} and the optional {cropX}/{cropY}.
        $segments = explode('/', $path);

        while ($segments !== [] && is_numeric($segments[0])) {
            array_shift($segments);
        }

        return $this->redirectToAsset(implode('/', $segments));
    }

    private function redirectToAsset(string $image): Response
    {
        $image = ltrim($image, '/');

        // Only venue logos were migrated; offer images (4.1 GB of dead data) were not.
        if (! str_starts_with($image, 'userfiles/locations/')) {
            abort(404);
        }

        $relative = substr($image, strlen('userfiles/locations/'));
        $relative = str_replace('..', '', $relative);

        if ($relative === '' || ! is_file(storage_path('app/public/locations/'.$relative))) {
            abort(404);
        }

        return redirect(asset('storage/locations/'.$relative), 301);
    }
}
