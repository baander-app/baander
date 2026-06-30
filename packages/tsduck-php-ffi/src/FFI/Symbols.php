<?php

declare(strict_types=1);

namespace Tsduck\FFI;

/**
 * Complete FFI C header declaration for the TSDuck tspy* C API.
 *
 * This class provides the single source of truth for all C function
 * signatures and struct layouts used by the PHP FFI bindings. Every
 * exported tspy* function and its argument structs must be declared here.
 *
 * Struct fields use their exact C types (long, size_t, const uint8_t*)
 * to match the C ABI on each platform (LP64 on Linux/macOS, LLP64 on Windows).
 */
final class Symbols
{
    /**
     * Returns the complete FFI::cdef() header string declaring all
     * tspy* exported functions and their argument structs.
     *
     * @return string The C header for FFI::cdef()
     */
    public static function header(): string
    {
        return <<<'C'
            // ---------------------------------------------------------------------------
            // TSDuck PHP FFI Bindings - Complete C API declaration
            // ---------------------------------------------------------------------------
            // Generated from src/libtsduck/python/private/tspy*.cpp
            // Every tspy* function and struct must be declared here with exact C types.
            // ---------------------------------------------------------------------------

            // === Structs ===

            struct tspyTSProcessorArgs {
                long ignore_joint_termination;
                long buffer_size;
                long max_flushed_packets;
                long max_input_packets;
                long max_output_packets;
                long initial_input_packets;
                long add_input_stuffing_0;
                long add_input_stuffing_1;
                long add_start_stuffing;
                long add_stop_stuffing;
                long bitrate;
                long bitrate_adjust_interval;
                long receive_timeout;
                long log_plugin_index;
                const uint8_t* plugins;
                size_t plugins_size;
            };

            struct tspyInputSwitcherArgs {
                long fast_switch;
                long delayed_switch;
                long terminate;
                long reuse_port;
                long first_input;
                long primary_input;
                long cycle_count;
                long buffered_packets;
                long max_input_packets;
                long max_output_packets;
                long sock_buffer;
                long remote_server_port;
                long receive_timeout;
                const uint8_t* plugins;
                size_t plugins_size;
                const uint8_t* event_command;
                size_t event_command_size;
                const uint8_t* event_udp_addr;
                size_t event_udp_addr_size;
                long event_udp_port;
                const uint8_t* local_addr;
                size_t local_addr_size;
                long event_ttl;
            };

            // === Info functions (tspyInfo.cpp) ===

            uint32_t tspyVersionInteger();
            void tspyVersionString(uint8_t* buffer, size_t* size);

            // === DuckContext functions (tspyDuckContext.cpp) ===

            void* tspyNewDuckContext(void* report);
            void tspyDeleteDuckContext(void* duck_ptr);
            int tspyDuckContextSetDefaultCharset(void* duck_ptr, const uint8_t* name, size_t name_size);
            void tspyDuckContextSetDefaultCASId(void* duck_ptr, uint16_t cas);
            void tspyDuckContextSetDefaultPDS(void* duck_ptr, uint32_t pds);
            void tspyDuckContextAddStandards(void* duck_ptr, uint32_t mask);
            void tspyDuckContextResetStandards(void* duck_ptr, uint32_t mask);
            uint32_t tspyDuckContextStandards(void* duck_ptr);
            void tspyDuckContextSetTimeReferenceOffset(void* duck_ptr, int64_t offset);
            int tspyDuckContextSetTimeReference(void* duck_ptr, const uint8_t* name, size_t name_size);

            // === SectionFile functions (tspySectionFile.cpp) ===

            void* tspyNewSectionFile(void* duck);
            void tspyDeleteSectionFile(void* sf);
            void tspySectionFileClear(void* sf);
            size_t tspySectionFileBinarySize(void* sf);
            size_t tspySectionFileSectionsCount(void* sf);
            size_t tspySectionFileTablesCount(void* sf);
            int tspySectionFileLoadBinary(void* sf, const uint8_t* name, size_t name_size);
            int tspySectionFileSaveBinary(void* sf, const uint8_t* name, size_t name_size);
            int tspySectionFileLoadXML(void* sf, const uint8_t* name, size_t name_size);
            int tspySectionFileSaveXML(void* sf, const uint8_t* name, size_t name_size);
            int tspySectionFileSaveJSON(void* sf, const uint8_t* name, size_t name_size);
            size_t tspySectionFileToXML(void* sf, uint8_t* buffer, size_t* size);
            size_t tspySectionFileToJSON(void* sf, uint8_t* buffer, size_t* size);
            int tspySectionLoadBuffer(void* sf, const uint8_t* buffer, size_t size);
            void tspySectionSaveBuffer(void* sf, uint8_t* buffer, size_t* size);
            void tspySectionFileSetCRCValidation(void* sf, int mode);
            void tspySectionFileReorganizeEITs(void* sf, int year, int month, int day);

            // === Report callback types ===

            typedef void* (*tspyLogCallback)(int severity, const uint8_t* message, size_t message_bytes);

            // Holder struct for PHP FFI callbacks. PHP FFI allows assigning
            // PHP closures to struct fields of function pointer type. This
            // struct provides such a field for storing the log callback.
            // The holder must be kept alive (referenced) as long as the C
            // code may invoke the callback.
            struct tspyCallbackHolder {
                tspyLogCallback log_callback;
            };

            // === Report functions (tspyReport.cpp) ===

            void tspyReportHeader(int severity, uint8_t* buffer, size_t* buffer_size);
            void* tspyStdErrReport();
            void* tspyNullReport();
            void* tspyNewAsyncReport(int severity, int sync_log, int timed_log, size_t log_msg_count);
            void tspyTerminateAsyncReport(void* report);
            void* tspyNewPyAsyncReport(void* log, int severity, int sync_log, size_t log_msg_count);
            void* tspyNewPySyncReport(void* log, int severity);
            void tspyDeleteReport(void* report);
            void tspySetMaxSeverity(void* report, int severity);
            void tspyLogReport(void* report, int severity, const uint8_t* buffer, size_t size);

            // === TSProcessor functions (tspyTSProcessor.cpp) ===

            void* tspyNewTSProcessor(void* report);
            void tspyDeleteTSProcessor(void* tsp);
            void tspyAbortTSProcessor(void* tsp);
            void tspyWaitTSProcessor(void* tsp);
            int tspyStartTSProcessor(void* tsp, const struct tspyTSProcessorArgs* pyargs);

            // === InputSwitcher functions (tspyInputSwitcher.cpp) ===

            void* tspyNewInputSwitcher(void* report);
            void tspyDeleteInputSwitcher(void* pyobj);
            void tspyStopInputSwitcher(void* pyobj);
            void tspyWaitInputSwitcher(void* pyobj);
            void tspyInputSwitcherSetInput(void* pyobj, size_t index);
            void tspyInputSwitcherNextInput(void* pyobj);
            void tspyInputSwitcherPreviousInput(void* pyobj);
            size_t tspyInputSwitcherCurrentInput(void* pyobj);
            int tspyStartInputSwitcher(void* pyobj, const struct tspyInputSwitcherArgs* pyargs);

            // === SystemMonitor functions (tspySystemMonitor.cpp) ===

            void* tspyNewSystemMonitor(void* report, const uint8_t* config, size_t config_size);
            void tspyDeleteSystemMonitor(void* pymon);
            void tspyStartSystemMonitor(void* pymon);
            void tspyStopSystemMonitor(void* pymon);
            void tspyWaitSystemMonitor(void* pymon);

            // === PluginEventHandler functions (tspyPluginEventHandler.cpp) ===

            void* tspyNewPyPluginEventHandler(void* callback);
            void tspyDeletePyPluginEventHandler(void* obj);
            void tspyPyPluginEventHandlerUpdateData(void* obj, void* data, size_t size);

            // === PluginEventHandlerRegistry functions (tspyPluginEventHandlerRegistry.cpp) ===

            void tspyPluginEventHandlerRegister(void* tsp, void* handler, uint32_t event_code);
            void tspyPluginEventHandlerRegisterInput(void* tsp, void* handler);
            void tspyPluginEventHandlerRegisterOutput(void* tsp, void* handler);

            // === PHP Polling Bridge functions (tspyphpPollingReport.cpp) ===

            void* tspyphpNewPollingAsyncReport(int severity, int sync_log, size_t log_msg_count, size_t max_queue_size);
            int tspyphpPollReportMessages(void* report, uint8_t* buffer, size_t* buffer_size, int timeout_ms);
            void tspyphpDeletePollingAsyncReport(void* report);

            // === PHP Polling Bridge functions (tspyphpPollingPluginEventHandler.cpp) ===

            void* tspyphpNewPollingPluginEventHandler(size_t max_queue_size);
            int tspyphpPollPluginEvents(void* handler, uint8_t* buffer, size_t* buffer_size, int timeout_ms);
            void tspyphpCompletePluginEvent(void* handler, uint64_t event_id, int success, const uint8_t* data, size_t data_size);
            void tspyphpDeletePollingPluginEventHandler(void* handler);
            C;
    }
}
