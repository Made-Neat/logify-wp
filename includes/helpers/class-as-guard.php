<?php
namespace Logify_WP;

final class AS_Guard {
  public static function max_bytes(): int {
    return 7500; // headroom under the ~8k limit
  }

  public static function enqueue_async(string $hook, array $args = [], string $group = '', bool $unique = false): void {
      // shrink arguments without nesting
      $shrunk = self::shrink($args, self::max_bytes());

      // honour $unique flag
      if ($unique && function_exists('as_has_scheduled_action') &&
          as_has_scheduled_action($hook, $shrunk, $group)) {
          return;
      }

      as_enqueue_async_action($hook, $shrunk, $group, $unique);
  }

  private static function shrink(array $payload, int $max_bytes): array {
    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false && strlen($json) <= $max_bytes) {
      return $payload;
    }

    // remove known heavy keys if present
    $strip = ['request','server','cookies','headers','files','raw_data','session','environment'];
    foreach ($strip as $k) { if (isset($payload[$k])) unset($payload[$k]); }

    // truncate long strings and bound array sizes
    $payload = self::truncate_deep($payload, [
      'max_depth' => 5, 'max_items' => 50,
      'max_string_bytes' => 500
    ]);

    $json = wp_json_encode($payload);
    if ($json !== false && strlen($json) <= $max_bytes) {
      return $payload;
    }
    ob_start();
    var_dump($json);
    $dump = ob_get_clean();
    Debug::warning('Logify WP: payload too large even after truncation, using stub. Dump: ' . $dump);

    // last resort stub
    return [
      'message' => isset($payload['message']) ? substr((string)$payload['message'], 0, 300) : '',
      'note'    => 'payload_truncated',
      'ts'      => time(),
    ];
  }

  private static function truncate_deep($value, array $limits, int $depth = 0) {
    if ($depth >= $limits['max_depth']) {
      return is_array($value) ? ['__truncated__' => true] : $value;
    }
    if (is_string($value)) {
      return mb_strlen($value, '8bit') > $limits['max_string_bytes']
             ? mb_strcut($value, 0, $limits['max_string_bytes'], 'UTF-8').'…'
             : $value;
    }
    if (is_array($value)) {
      $out = []; $i = 0;
      foreach ($value as $k => $v) {
        if ($i >= $limits['max_items']) { $out['__more__'] = true; break; }
        $out[$k] = self::truncate_deep($v, $limits, $depth + 1);
        $i++;
      }
      return $out;
    }
    return $value;
  }
}
