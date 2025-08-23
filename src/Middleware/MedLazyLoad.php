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
        '/<(img|iframe|source|video|audio)([^>]*?)src=/i',
        function ($matches) {
          $tag = $matches[1];
          $attrs = $matches[2];
    
          // If media="no-lazy" → keep src as-is
          if (preg_match('/media\s*=\s*"(no-lazy)"/i', $attrs)) {
            return "<{$tag}{$attrs}src="; // unchanged
          }
    
          // Otherwise → convert src → data-src
          return "<{$tag}{$attrs}data-src=";
        },
        $content
      );

      // style {background-image:url()}
      $content = preg_replace_callback(
        '/<([a-zA-Z]+)([^>]*?)style\s*=\s*"(.*?)background-image\s*:\s*url\((["\']?)(.*?)\4\)(.*?);?(.*?)"(.*?)>/i',
        function ($matches) {
          $tagName = $matches[1];
          $attrs   = $matches[2];
    
          // If media="no-lazy" → keep style as-is
          if (preg_match('/media\s*=\s*"(no-lazy)"/i', $attrs)) {
            return "<{$tagName}{$attrs} style=\"{$matches[3]}background-image:url({$matches[5]}){$matches[6]};{$matches[7]}\"{$matches[8]}>";
          }
    
          // Remove background-image from inline style
          $styleWithoutBg = trim(preg_replace('/background-image\s*:\s*url\((["\']?).*?\1\);?/', '', $matches[3]));
          $newStyle = !empty($styleWithoutBg) ? 'style="' . $styleWithoutBg . '"' : '';
          return "<{$tagName}{$attrs} $newStyle data-bg=\"{$matches[5]}\" {$matches[8]}>";
        },
        $content
      );

      // JavaScript code
      $javascript = "<script>
          let loadMedia = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
              if (entry.isIntersecting) {
                let ele = entry.target;
                if (['IMG', 'IFRAME'].includes(ele.tagName)) {
                  const dataSrc = ele.getAttribute('data-src');
                  if (dataSrc) {
                    ele.setAttribute('src', dataSrc);
                    ele.removeAttribute('data-src');
                  }
                }
                if (ele.tagName === 'SOURCE') {
                  const dataSrc = ele.getAttribute('data-src');
                  if (dataSrc) {
                    ele.setAttribute('src', dataSrc);
                    ele.removeAttribute('data-src');
                    ele.closest('video, audio').load();
                  }
                }
                if (ele.hasAttribute('data-bg')) {
                  const bgUrl = ele.getAttribute('data-bg');
                  ele.style.backgroundImage = 'url(' + bgUrl + ')';
                  ele.removeAttribute('data-bg');
                }
                observer.unobserve(ele);
              }
            });
          }, {
            rootMargin: '" . config('medialazyload.rootMargin') . "',
            threshold: " . config('medialazyload.threshold') . "
          });

          document.querySelectorAll('img[data-src], iframe[data-src], source[data-src], [data-bg]').forEach((element) => {
            loadMedia.observe(element);
          });
        </script>";

      // JQuery code
      $jquery = "<script>
          let loadMedia = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
              if (entry.isIntersecting) {
                const ele = $(entry.target);
                if (ele.is('img, iframe')) {
                  const dataSrc = ele.data('src');
                  if (dataSrc) { ele.attr('src', dataSrc).removeAttr('data-src'); }
                }
                if (ele.is('source')) {
                  const dataSrc = ele.data('src');
                  if (dataSrc) {
                    ele.attr('src', dataSrc).removeAttr('data-src');
                    ele.closest('video, audio')[0].load();
                  }
                }
                if (ele.data('bg')) {
                  const bgUrl = ele.data('bg');
                  ele.css('background-image', 'url(' + bgUrl + ')').removeAttr('data-bg');
                }
                observer.unobserve(entry.target);
              }
            });
          }, {
            rootMargin: '" . config('medialazyload.rootMargin') . "',
            threshold: " . config('medialazyload.threshold') . "
          });

          $('img[data-src], iframe[data-src], source[data-src], [data-bg]').each(function() {
            loadMedia.observe(this);
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
