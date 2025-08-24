<?php

namespace Debjyotikar001\MediaLazyLoad\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MedLazyLoad
{
  /**
   * Handle an incoming request.
   *
   * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
   */
  public function handle(Request $request, Closure $next): Response
  {
    $response = $next($request);

    if ($response->isSuccessful() && config('medialazyload.enabled')) {
      $content = $response->getContent();

      if (!in_array(config('app.env'), explode(',', config('medialazyload.allowed_envs')))) { return $response; }

      if (!empty(config('medialazyload.skip_urls'))) {
        $currentUrl = $request->path();
        foreach (config('medialazyload.skip_urls') as $item) {
          if (Str::is($item, $currentUrl)) { return $response; }
        }
      }

      // img, iframe, video and audio
      $content = preg_replace_callback(
        '/<(img|iframe|source|video|audio)([^>]*?)>/i',
        function ($matches) {
          $fullTag = $matches[0];
          
          // If media="no-lazy" → keep src as-is
          if (preg_match('/media\s*=\s*["\']no-lazy["\']/', $fullTag)) {
            return $fullTag; // unchanged
          }
      
          // Otherwise → convert src → data-media-src
          if (preg_match('/\ssrc\s*=/', $fullTag)) {
            return preg_replace('/\ssrc\s*=/', ' data-media-src=', $fullTag);
          }
      
          return $fullTag;
        },
        $content
      );

      // style {background-image:url()}
      $content = preg_replace_callback(
        '/<([a-zA-Z]+)([^>]*?)style\s*=\s*"(.*?)background-image\s*:\s*url\((["\']?)(.*?)\4\)(.*?);?(.*?)"(.*?)>/i',
        function ($matches) {
          $fullTag = $matches[0];

          // If media="no-lazy" → keep style as-is
          if (preg_match('/media\s*=\s*["\']no-lazy["\']/', $fullTag)) {
            return $fullTag; // unchanged
          }

          $tagName = $matches[1];
          $attrs   = $matches[2];
          
          // Remove background-image from inline style
          $styleWithoutBg = trim(preg_replace('/background-image\s*:\s*url\((["\']?).*?\1\);?/', '', $matches[3]));
          $newStyle = !empty($styleWithoutBg) ? 'style="' . $styleWithoutBg . '"' : '';
          return "<{$tagName}{$attrs} $newStyle data-media-bg=\"{$matches[5]}\" {$matches[8]}>";
        },
        $content
      );

      // JavaScript code
      $javascript = "<script>
            // Simple and direct lazy loading
            const observer = new IntersectionObserver((entries) => {
              entries.forEach(entry => {
                if (entry.isIntersecting) {
                  const el = entry.target;
                  
                  // Handle data-media-src
                  if (el.hasAttribute('data-media-src')) {
                    el.setAttribute('src', el.getAttribute('data-media-src'));
                    el.removeAttribute('data-media-src');
                    
                    // Reload video/audio if needed
                    if (el.tagName === 'VIDEO' || el.tagName === 'AUDIO') {
                      el.load();
                    }
                    
                    // Reload parent video/audio for source tags
                    if (el.tagName === 'SOURCE') {
                      const parent = el.parentElement;
                      if (parent && (parent.tagName === 'VIDEO' || parent.tagName === 'AUDIO')) {
                        parent.load();
                      }
                    }
                  }
                  
                  // Handle data-media-bg
                  if (el.hasAttribute('data-media-bg')) {
                    el.style.backgroundImage = 'url(' + el.getAttribute('data-media-bg') + ')';
                    el.removeAttribute('data-media-bg');
                  }
                  
                  observer.unobserve(el);
                }
              });
            }, {
              rootMargin: '" . config('medialazyload.rootMargin') . "',
              threshold: " . config('medialazyload.threshold') . "
            });

            // Start observing when page loads
            window.addEventListener('load', () => {
              document.querySelectorAll('[data-media-src], [data-media-bg]').forEach(el => observer.observe(el));
            });
          </script>";

      // JQuery code
      $jquery = "<script>
            const observer = new IntersectionObserver((entries) => {
              entries.forEach(entry => {
                if (entry.isIntersecting) {
                  const \$el = \$(entry.target);
                  
                  // Handle data-media-src
                  if (\$el.attr('data-media-src')) {
                    \$el.attr('src', \$el.attr('data-media-src')).removeAttr('data-media-src');
                    
                    // Reload video/audio if needed
                    if (entry.target.tagName === 'VIDEO' || entry.target.tagName === 'AUDIO') {
                      entry.target.load();
                    }
                    
                    // Reload parent video/audio for source tags
                    if (entry.target.tagName === 'SOURCE') {
                      const parent = \$el.parent('video, audio')[0];
                      if (parent) parent.load();
                    }
                  }
                  
                  // Handle data-media-bg
                  if (\$el.attr('data-media-bg')) {
                    \$el.css('background-image', 'url(' + \$el.attr('data-media-bg') + ')').removeAttr('data-media-bg');
                  }
                  
                  observer.unobserve(entry.target);
                }
              });
            }, {
              rootMargin: '" . config('medialazyload.rootMargin') . "',
              threshold: " . config('medialazyload.threshold') . "
            });
        
            // Start observing when document ready
            \$(document).ready(() => {
              \$('[data-media-src], [data-media-bg]').each(function() {
                observer.observe(this);
              });
            });
          </script>";
  
      $javascriptCode = $javascript;
      if (config('medialazyload.jquery')) {
        $content = str_replace('</head>', '<script src="' . config('medialazyload.jqueryUrl') . '"></script></head>', $content);
        $javascriptCode = $jquery;
      }

      $content = str_replace('</body>', $javascriptCode . '</body>', $content);

      $response->setContent($content);
    }

    return $response;
  }
}
