{
  "targets": [
    {
      "target_name": "baander_aac",
      "sources": [
        "node/src/addon.cc",
        "src/tables.cpp",
        "src/aac_cpu.cpp",
        "src/dsp.cpp",
        "src/fft.cpp",
        "src/mdct.cpp",
        "src/lowdelay.cpp",
        "src/eld_tables.cpp",
        "src/ics.cpp",
        "src/bitstream.cpp",
        "src/channel_map.cpp",
        "src/qmf.cpp",
        "src/sbr_tables.cpp",
        "src/sbr_freq.cpp",
        "src/ps_hybrid.cpp",
        "src/asc.cpp",
        "src/latm.cpp",
        "src/transport.cpp",
        "src/drc.cpp",
        "src/unidrc_parse.cpp",
        "src/unidrc_gain.cpp",
        "src/unidrc.cpp",
        "src/api.cpp",
        "src/decoder.cpp",
        "src/decoder_output.cpp",
        "src/limiter.cpp",
        "src/conceal.cpp",
        "src/er_vcb11.cpp",
        "src/er_rvlc.cpp",
        "src/er_hcr.cpp",
        "src/usac_lpd.cpp",
        "src/usac_lpc.cpp",
        "src/usac_acelp.cpp",
        "src/usac_tcx.cpp",
        "src/usac_fac.cpp",
        "src/usac_lpd_tables.cpp",
        "src/sbr_dec.cpp",
        "src/sbr_esbr.cpp",
        "src/ps_dec.cpp",
        "src/mps212.cpp",
        "src/mps212_tables.cpp",
        "src/psycho.cpp",
        "src/encoder_tools.cpp",
        "src/afterburner.cpp",
        "src/encoder.cpp",
        "src/sbr_enc.cpp",
        "src/ps_enc.cpp",
        "src/qmf_ld.cpp",
        "src/sbr_ld.cpp",
        "src/sbr_ld_enc.cpp",
        "src/usac_config.cpp",
        "src/usac_frame.cpp",
        "src/usac_arith_tables.cpp",
        "src/usac_arith.cpp",
        "src/usac_stereo.cpp",
        "src/usac_fd.cpp"
      ],
      "include_dirs": [
        "<!@(node -p \"require('node-addon-api').include\")",
        "include"
      ],
      "defines": [
        "NAPI_DISABLE_CPP_EXCEPTIONS",
        "NAPI_VERSION=8",
        "BAAC_AAC_HAVE_ENCODER",
        "BAAC_AAC_HAVE_DECODER"
      ],
      "cflags_cc": ["-std=c++17", "-O3", "-fvisibility=hidden", "-ffp-contract=off"],
      "cflags_cc!": ["-fno-exceptions", "-fno-rtti"],
      "xcode_settings": {
        "CLANG_CXX_LANGUAGE_STANDARD": "c++17",
        "GCC_ENABLE_CPP_EXCEPTIONS": "YES",
        "MACOSX_DEPLOYMENT_TARGET": "11.0",
        "OTHER_CFLAGS": ["-O3", "-ffp-contract=off"]
      },
      "msvs_settings": {
        "VCCLCompilerTool": {
          "ExceptionHandling": 1,
          "AdditionalOptions": ["/std:c++17", "/O2"]
        }
      },
      "conditions": [
        ["OS=='win'", {"defines": ["_USE_MATH_DEFINES", "NOMINMAX"]}],
        ["target_arch=='x64' or target_arch=='ia32'", {
          "sources": ["src/simd/sse2.cpp"],
          "defines": ["BAAC_AAC_SSE2"],
          "cflags_cc": ["-msse2"]
        }],
        ["target_arch=='arm64'", {
          "sources": ["src/simd/neon.cpp"],
          "defines": ["BAAC_AAC_NEON"]
        }]
      ]
    }
  ]
}
