<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} - Geeliva</title>

<meta property="og:type" content="website">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
@if($photo)<meta property="og:image" content="{{ $photo }}">@endif
<meta property="og:url" content="{{ $pageUrl }}">
<meta name="twitter:card" content="summary_large_image">

{{-- If the app is installed and the platform link association is verified, the OS
     intercepts this URL before the page ever loads -- everything below only renders
     for visitors who fell through (no app, or verification hasn't propagated yet). --}}

<style>
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;background:#f9fafb;color:#111827}
  .wrap{max-width:480px;margin:0 auto;padding:24px 16px;text-align:center}
  .photo{width:100%;max-width:320px;border-radius:16px;aspect-ratio:1/1;object-fit:cover;margin:16px auto;display:block;background:#e5e7eb}
  h1{font-size:22px;margin:8px 0}
  .price{font-size:18px;font-weight:600;color:#FF6839;margin:4px 0}
  p{color:#6b7280;font-size:15px;line-height:1.4}
  .btn{display:inline-block;margin-top:16px;padding:14px 28px;background:#FF6839;color:#fff;text-decoration:none;border-radius:999px;font-weight:600}
  .store-links{margin-top:24px}
  .store-links a{display:inline-block;margin:4px}
</style>
</head>
<body>
<div class="wrap">
  @if($photo)<img class="photo" src="{{ $photo }}" alt="{{ $title }}">@endif
  <h1>{{ $title }}</h1>
  @if($price)<div class="price">{{ $price }}</div>@endif
  @if($description)<p>{{ $description }}</p>@endif
  <a class="btn" href="{{ $deepLink }}">Open in Geeliva</a>
  <div class="store-links">
    <a href="https://play.google.com/store/apps/details?id=com.geeliva_cm.customer">Get it on Google Play</a>
  </div>
</div>
</body>
</html>
