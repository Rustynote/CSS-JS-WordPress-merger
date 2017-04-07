# CSS JS Queue Merger

For this plugin to work as intended, styles and js must be properly enqueued. Plugin uses style/script handle name combined with version to form a hash which is then used for cache file name. This means new file will be automatically generated if plugin or theme is updated, this also means if some page requires more or different css/js new file will be generated.

Scripts can be split to include in header or footer, or include them in footer only.

####This just merges and minifys the files so if there's syntax error somewhere, it will probably break everything.
