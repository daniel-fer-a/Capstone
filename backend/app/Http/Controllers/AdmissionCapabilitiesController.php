<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AdmissionCapabilitiesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $pilot = !config('admissions.require_member_sponsors') && app()->environment(['local', 'testing']);

        return response()->json(['data' => [
            'natural_submission_enabled' => $pilot,
            'member_sponsors_required' => !$pilot,
            'sponsor_channel_available' => (bool) config('admissions.sponsorships_enabled'),
            'juridical_submission_enabled' => (bool) config('admissions.sponsorships_enabled'),
            'staged_submission_enabled' => (bool) config('admissions.sponsorships_enabled'),
            'local_pilot' => $pilot,
        ]])->header('Cache-Control', 'no-store');
    }
}
