# query-id-cache

Caches the ID lists and found-rows of expensive WP_Query runs in one transient via posts_pre_query, for anonymous requests only, keyed on the query signature, invalidated on publish. Turns a multi-second home page into a sub-second one on sites with no object cache.
