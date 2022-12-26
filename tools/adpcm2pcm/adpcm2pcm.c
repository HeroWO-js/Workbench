// MSVC 2017 with Windows XP support:
//
// cl /W4 /O2 /Oy /GL /MT /D_UNICODE /D_USING_V110_SDK71_ adpcm2pcm.c /link /RELEASE /SUBSYSTEM:CONSOLE,"5.01"
//
// StripReloc.exe /B /C adpcm2pcm.exe
//
// [musl-]gcc -Wall -Wextra -O3 -s -static -o adpcm2pcm adpcm2pcm.c

#define DR_WAV_IMPLEMENTATION
#include "dr_wav.h"

#ifdef _WIN32
#ifdef _UNICODE
#define DRWAV_USE_UNICODE
#endif
#endif

#ifdef DRWAV_USE_UNICODE
#define tchar wchar_t
#define _T(x) L ## x
#define tmain wmain
#define tstrcmp wcscmp
#define tdrwav_init_file drwav_init_file_w
#define tdrwav_init_file_write drwav_init_file_write_w
#else
#define tchar char
#define _T(x) x
#define tmain main
#define tstrcmp strcmp
#define tdrwav_init_file drwav_init_file
#define tdrwav_init_file_write drwav_init_file_write
#endif

int tmain(int argc, tchar* argv[])
{
  if (argc != 3 && (argc != 4 || tstrcmp(argv[3], _T("-f")))) {
    printf(
      "Usage: adpcm2pcm.exe input.wav output.wav [-f]\n"
      "Input should be DVI-ADPCM, 1-2 channels, 22050 Hz, 4 bits per sample\n"
      "-f attempts to convert even if this is not the case\n"
      "Produced by herowo.game | Based on dr_wav.h v%d.%d.%d | Public domain or MIT\n"
#ifdef _WIN32
#ifndef DRWAV_USE_UNICODE
      "This version is not Unicode-safe\n"
#endif
#endif
      "https://github.com/mackron/dr_libs\n",
      DRWAV_VERSION_MAJOR, DRWAV_VERSION_MINOR, DRWAV_VERSION_REVISION
    );
    return 1;
  }

  tchar* inputFile = argv[1];
  tchar* outputFile = argv[2];
  drwav wavInput = { 0 };

  if (!tdrwav_init_file(&wavInput, inputFile, NULL)) {
    printf("Failed to open input file\n");
    return 2;
  }

  if ((!argv[3] || tstrcmp(argv[3], _T("-f"))) &&
      ((wavInput.translatedFormatTag & DR_WAVE_FORMAT_DVI_ADPCM) == 0
       || wavInput.channels > 2
       || wavInput.sampleRate != 22050
       || wavInput.bitsPerSample != 4)) {
    printf("Unsupported input: format 0x%X (0x%X), %d channels, %d Hz, %d bits per sample\n", wavInput.translatedFormatTag, wavInput.fmt.formatTag, wavInput.channels, wavInput.sampleRate, wavInput.bitsPerSample);
    printf("Give -f to try to convert anyway\n");
    return 3;
  }

  drwav wavOutput = { 0 };
  drwav_data_format formatOutput;
  formatOutput.container = drwav_container_riff;
  formatOutput.format = DR_WAVE_FORMAT_PCM;
  formatOutput.channels = wavInput.channels;
  formatOutput.sampleRate = wavInput.sampleRate;
  formatOutput.bitsPerSample = wavInput.bitsPerSample * sizeof(drwav_int64) / sizeof(drwav_int16);

  if (!tdrwav_init_file_write(&wavOutput, outputFile, &formatOutput, NULL)) {
    printf("Failed to open output file\n");
    return 4;
  }

  const size_t sizeFrames = (size_t)(wavInput.totalPCMFrameCount * wavInput.channels * sizeof(drwav_int16));
  drwav_int16* pDecodedInterleavedPCMFrames = malloc(sizeFrames);
  size_t numberOfSamplesActuallyDecoded = (size_t)drwav_read_pcm_frames_s16(&wavInput, wavInput.totalPCMFrameCount, (drwav_int16*)pDecodedInterleavedPCMFrames);
  drwav_write_pcm_frames(&wavOutput, numberOfSamplesActuallyDecoded, pDecodedInterleavedPCMFrames);

  drwav_uninit(&wavOutput);
  drwav_uninit(&wavInput);
}
