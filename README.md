# CSS JS Queue Merger

For this plugin to work as intended, styles and scripts must be properly enqueued. Plugin uses style/script handle name combined with version to form a hash which is then used for file name. This means new file will be automatically generated if plugin or theme is updated, this also means if some page requires more or different css/js new file will be generated.

This plugin was made to be lightweight as much as possible, it just mergers and minifies the files, so if there's syntax error in some file, it will probably break everything. Use it only if you know what you are doing.

###Features

- Merge and minify styles on the go.
- Merge and minify scripts on the go.
- Include scripts in the header and footer (default).
- Force all scripts into the footer.
- Exclude all external files.
- Fields for ignoring URLs with `*` as wildcard.
- If file cannot be fetched, it will be added into error field and be ignored in next iteration.
- Clear cache button.
- Ignore administrator.

#

In the merged file, on beginning of each file there's a handle and a source url so you can easily debug it if some file is causing the problems.
