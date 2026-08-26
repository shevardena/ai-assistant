<?php

namespace App\Enums;

enum TemplateSetupAction: string
{
    case None = 'none';
    case CreateDataset = 'create_dataset';
    case ConnectDataSource = 'connect_data_source';
    case ConfigureLiveApi = 'configure_live_api';
    case ConfigureWriteApi = 'configure_write_api';
    case ConfigureChannel = 'configure_channel';
    case OpenCapabilities = 'open_capabilities';
    case OpenWorkflows = 'open_workflows';
    case RunBotTest = 'run_bot_test';
}
