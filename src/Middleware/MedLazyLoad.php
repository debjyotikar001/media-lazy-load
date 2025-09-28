<?php

namespace Debjyotikar001\MediaLazyLoad\Middleware;

use Closure;
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

    if (!$response->isSuccessful()) return $response;

    // Only process HTML responses
    if (!str_contains($response->headers->get('Content-Type'), 'text/html')) return $response;

    $content = $response->getContent();

    /*
    |--------------------------------------------------------------------------
    | HTML Lazy Loading (Blade @lazyHtml / @endLazyHtml)
    |--------------------------------------------------------------------------
    */
    $excludedAgents = config('medialazyload.excluded_user_agents', []);
    if (!empty($excludedAgents)) {
      $userAgent = $request->userAgent();

      foreach ($excludedAgents as $agent) {
        if ($agent && stripos($userAgent, $agent) !== false) {
          // Remove <template> wrappers for excluded user agents
          $content = preg_replace(
            [
              '/<template[^>]*class="lazy-html"[^>]*data-lazyhtml="true"[^>]*>/i',
              '/<\/template\s*data-lazyhtml>/i',
            ],
            ['', ''],
            $content
          );

          break;
        }
      }
    }

    // JavaScript code
    $htmlJs = "<script>
          function revealTemplate(template) {
            // Clone template content and insert before the template
            template.parentNode.insertBefore(template.content.cloneNode(true), template);

            // Remove the template itself
            template.remove();
          }

          function initLazyTemplates() {
            const templates = document.querySelectorAll('template.lazy-html[data-lazyhtml=\"true\"]');

            if (!(\"IntersectionObserver\" in window)) {
              // Fallback: load all immediately
              templates.forEach(t => revealTemplate(t));
              return;
            }

            const observer = new IntersectionObserver((entries, obs) => {
              entries.forEach(entry => {
                if (entry.isIntersecting) {
                  revealTemplate(entry.target);
                  obs.unobserve(entry.target); // Stop observing once revealed
                }
              });
            }, {
              rootMargin: '" . config('medialazyload.rootMargin') . "',
              threshold: " . config('medialazyload.threshold') . "
            });

            templates.forEach(t => observer.observe(t));
          }

          // Start observing when page loads
          window.addEventListener('load', initLazyTemplates);
        </script>";

    // Add Javascript code
    $content = str_replace('</body>', $htmlJs . '</body>', $content);

    /*
    |--------------------------------------------------------------------------
    | Media Lazy Loading
    |--------------------------------------------------------------------------
    */
    if (config('medialazyload.enabled')) {
      // Allowed environments
      if (!in_array(config('app.env'), explode(',', config('medialazyload.allowed_envs')))) {
        $response->setContent($content);
        return $response;
      }

      // Skip urls
      if (!empty(config('medialazyload.skip_urls'))) {
        $currentUrl = $request->path();
        foreach (config('medialazyload.skip_urls') as $item) {
          if (fnmatch($item, $currentUrl)) {
            $response->setContent($content);
            return $response;
          }
        }
      }

      // img, iframe, source, video and audio
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
      $mediaJs = "<script>
            // Simple and direct lazy loading
            const mediaObserver = new IntersectionObserver((entries) => {
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
                  
                  mediaObserver.unobserve(el); // Stop observing
                }
              });
            }, {
              rootMargin: '" . config('medialazyload.rootMargin') . "',
              threshold: " . config('medialazyload.threshold') . "
            });

            // Start observing when page loads
            window.addEventListener('load', () => {
              document.querySelectorAll('[data-media-src], [data-media-bg]').forEach(el => mediaObserver.observe(el));
            });
          </script>";

      // Add Javascript code
      $content = str_replace('</body>', $mediaJs . '</body>', $content);
    }

    $response->setContent($content);

    return $response;
  }
}
