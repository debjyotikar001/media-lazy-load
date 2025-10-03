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
          
          $response->setContent($content);
          return $response;
        }
      }
    }

    if (preg_match('/<template[^>]*class="lazy-html"[^>]*data-lazyhtml="true"[^>]*>/i', $content)) {
      // JavaScript code
      $htmlJs = "<script>
            function revealTemplate(template) {
              // Clone template content and insert before the template
              template.parentNode.insertBefore(template.content.cloneNode(true), template);
              template.remove(); // Remove the template itself
            }
  
            function initLazyTemplates() {
              const templates = Array.from(document.querySelectorAll('template.lazy-html[data-lazyhtml=\"true\"]'));
              if (!templates.length) return;
              
              // Create a sentinel for each template and keep them in an array (same order)
              const sentinels = templates.map(t => {
                const s = document.createElement('div');
                s.className = 'lazy-html-sentinel';
                s.style.cssText = 'width:100%;height:1px;visibility:hidden;pointer-events:none;';
                s._lazyTemplate = t;
                t.parentNode.insertBefore(s, t);
                return s;
              });
              
              if (!(\"IntersectionObserver\" in window)) {
                // Fallback: reveal all immediately
                templates.forEach(t => revealTemplate(t));
                sentinels.forEach(s => s.remove());
                return;
              }
  
              let currentIndex = 0;
              const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                  if (!entry.isIntersecting) return;
  
                  const sentinel = entry.target;
                  const tpl = sentinel._lazyTemplate;
                  if (tpl) revealTemplate(tpl);
  
                  obs.unobserve(sentinel); // Stop observing once revealed
                  sentinel.remove();
  
                  // move to next sentinel and observe it
                  currentIndex = sentinels.indexOf(sentinel) + 1;
                  if (currentIndex < sentinels.length) {
                    observer.observe(sentinels[currentIndex]);
                  }
                });
              }, {
                rootMargin: '" . config('medialazyload.rootMargin') . "',
                threshold: " . config('medialazyload.threshold') . "
              });
  
              // Start by observing only the first sentinel
              if (sentinels[0]) observer.observe(sentinels[0]);
            }
  
            document.addEventListener('DOMContentLoaded', initLazyTemplates);
          </script>";
  
      // Add Javascript code
      $content = str_replace('</body>', $htmlJs . '</body>', $content);
    }

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

            // Function to observe all lazy elements
            function observeLazyMedia(root = document) {
              root.querySelectorAll('[data-media-src], [data-media-bg]').forEach(el => mediaObserver.observe(el));
            }

            // On initial page load
            document.addEventListener('DOMContentLoaded', () => {
              observeLazyMedia();

              // Watch for future DOM changes
              const mutationObserver = new MutationObserver((mutations) => {
                mutations.forEach(mutation => {
                  mutation.addedNodes.forEach(node => {
                    if (node.nodeType === 1) { // only element nodes
                      if (node.hasAttribute && (node.hasAttribute('data-media-src') || node.hasAttribute('data-media-bg'))) {
                        mediaObserver.observe(node);
                      }
                      // also check inside injected containers
                      observeLazyMedia(node);
                    }
                  });
                });
              });

              mutationObserver.observe(document.body, { childList: true, subtree: true });
            });
          </script>";

      // Add Javascript code
      $content = str_replace('</body>', $mediaJs . '</body>', $content);
    }

    $response->setContent($content);

    return $response;
  }
}
