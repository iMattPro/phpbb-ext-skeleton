{{ EXTENSION.vendor_name }}_{{ EXTENSION.extension_name }}_controller:
    path: /demo/{{ '{' }}name{{ '}' }}
    defaults: { _controller: {{ EXTENSION.vendor_name }}.{{ EXTENSION.extension_name }}.controller:handle }
