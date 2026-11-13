#define _GNU_SOURCE
#include <brotli/decode.h>
#include <brotli/encode.h>
#include <dlfcn.h>
#include <stdio.h>
#include <string.h>

int main(void) {
    const char *marker = BrotliDecoderErrorString((BrotliDecoderErrorCode)-17);
    Dl_info origin = {0};
    const void *symbol = dlsym(RTLD_DEFAULT, "BrotliDecoderErrorString");
    unsigned version = BrotliDecoderVersion();
    if (!symbol || !dladdr(symbol, &origin) || !origin.dli_fname) {
        fputs("Cannot resolve the loaded Brotli decoder.\n", stderr);
        return 2;
    }
    printf("decoder=%u.%u.%u marker=%s library=%s\n",
           version >> 24, (version >> 12) & 4095, version & 4095,
           marker ? marker : "missing", origin.dli_fname);
    /* Brotli 1.0 reports the error name without the newer branch's prefix. */
    if (!marker || strcmp(marker, "BLOCK_SWITCH") != 0) {
        fputs("The loaded decoder lacks the CVE-2026-111497 fix.\n", stderr);
        return 1;
    }

    /* Only valid bytes produced here are decoded; no malformed corpus is used. */
    const unsigned char input[] = "Durable Workflow ordinary Brotli response";
    unsigned char encoded[1024];
    unsigned char decoded[sizeof(input)];
    size_t encoded_size = sizeof(encoded);
    size_t decoded_size = sizeof(decoded);
    if (!BrotliEncoderCompress(4, 22, BROTLI_MODE_GENERIC, sizeof(input) - 1,
                              input, &encoded_size, encoded) ||
        BrotliDecoderDecompress(encoded_size, encoded, &decoded_size, decoded) !=
            BROTLI_DECODER_RESULT_SUCCESS ||
        decoded_size != sizeof(input) - 1 ||
        memcmp(decoded, input, sizeof(input) - 1) != 0) {
        fputs("Ordinary Brotli data did not round trip.\n", stderr);
        return 2;
    }
    puts("Valid Brotli data round trips through the corrected system decoder.");
    return 0;
}
