@component('mail::message')
# AI spend is approaching your monthly cap

Month-to-date spend for **{{ $month }}** has reached **${{ number_format($spentUsd, 2) }}** — {{ (int) $thresholdPercentage }}% of your **${{ number_format($capUsd, 2) }}** cap.

No agents have been blocked. Runs will continue as normal for the rest of the month.

You'll only receive this warning once per calendar month.

Thanks,
{{ config('app.name') }}
@endcomponent
