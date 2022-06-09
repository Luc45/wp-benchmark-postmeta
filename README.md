This is a small WordPress plugin adds a WP-CLI command that benchmarks postmeta performance.

This was done to answer a question: When does it make sense to use a custom table instead of postmeta in a WordPress plugin?

A lot of big plugins have migrated to a custom table structure due to poor scalability performance of the postmeta. This benchmark was put together to measure exactly where the trade-off of complexity vs. speed of a custom table makes sense.

The benchmarks suggests that there's a **linear** time increase **when inserting** posts with multiple postmeta, and an **exponential** time increase **when getting** posts by their postmeta value.

![image](https://user-images.githubusercontent.com/9341686/172850032-f5a9199a-caeb-4f7a-aea0-77bc7b770e61.png)

The code that results in those times is something similar to this:

```php
wp_insert_post( [
  'post_title' => wp_generate_uuid4(),
  'post_type'  => 'benchmark',
  'meta_input' => [
    'foo1' => 'foo1',
    'foo2' => 'foo2',
    'foo3' => 'foo3',
    'foo4' => 'foo4',
    'foo5' => 'foo5',
    'foo6' => 'foo6',
    'foo7' => 'foo7',
    'foo8' => 'foo8',
    'foo9' => 'foo9',
    'foo10' => 'foo10',
    'foo11' => 'foo11',
    'foo12' => 'foo12',
  ],
] );
        
get_posts( [
  'posts_per_page'   => 1,
  'post_type'        => 'benchmark',
  'post_status'      => 'draft',
  'meta_query'       => [
    ['key' => 'foo1','value' => 'foo1',],
    ['key' => 'foo2','value' => 'foo2',],
    ['key' => 'foo3','value' => 'foo3',],
    ['key' => 'foo4','value' => 'foo4',],
    ['key' => 'foo5','value' => 'foo5',],
    ['key' => 'foo6','value' => 'foo6',],
    ['key' => 'foo7','value' => 'foo7',],
    ['key' => 'foo7','value' => 'foo8',],
    ['key' => 'foo7','value' => 'foo9',],
    ['key' => 'foo7','value' => 'foo10',],
    ['key' => 'foo12','value' => 'foo11',],
    ['key' => 'foo12','value' => 'foo12',],
  ],
  'cache_results'    => false,
  'suppress_filters' => true,
  'fields'           => 'ids',
] );
```


### Usage:
- Place `benchmark-postmeta.php` in your wp-content/plugins folder
- Run `wp benchmark run --post-mode=100_posts --postmeta-min=0 --postmeta-max=10`

This will:
- Insert 100 posts with 0 postmetas, and benchmark it.
- Insert 100 posts with 1 postmeta, and benchmark it.
- Insert 100 posts with 2 postmetas, and benchmark it. And so on until 10 postmetas.
- Then it fetches 1 random post using 0 postmetas, and benchmark it.
- Fetches 1 random post using 1 postmeta, and benchmark it.
- Provide a link to a graph of the benchmark, similar to the image above, which you can access in the browser.

### Other branches:
- [multi-thread](https://github.com/Luc45/wp-benchmark-postmeta/tree/multi-thread): An attempt to perform the benchmarks in multi-thread. The multi-threading works, but to be effective it needs to run each thread in a separate db/table, which I didn't pursue due to time reasons.
- [alternate-postmeta](https://github.com/Luc45/wp-benchmark-postmeta/tree/alternate-postmeta) - A suggestion made in Slack to change the way WordPress builds the query when using `get_posts` with `meta_query`. Instead of multiple JOINs, it generates another query. It solves the performance issue, but I haven't had time to confirm if the results remain accurate.
