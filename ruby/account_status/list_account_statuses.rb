#!/usr/bin/env ruby
# Encoding: utf-8
#
# Copyright:: Copyright 2016, Google Inc. All Rights Reserved.
#
# License:: Licensed under the Apache License, Version 2.0 (the "License");
#           you may not use this file except in compliance with the License.
#           You may obtain a copy of the License at
#
#           http://www.apache.org/licenses/LICENSE-2.0
#
#           Unless required by applicable law or agreed to in writing, software
#           distributed under the License is distributed on an "AS IS" BASIS,
#           WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
#           implied.
#           See the License for the specific language governing permissions and
#           limitations under the License.
#
# Gets the account information for the current account's subaccounts.

require_relative "account_status_common"

def list_account_statuses(
    content_api, merchant_id, next_page = nil, page_size=50)
  content_api.list_accountstatuses(
    merchant_id,
    max_results: page_size,
    page_token: next_page
  ) do |res, err|
    if err
      handle_errors(err)
      exit
    end
    res.resources.each do |status|
      print_account_status(status)
    end
    return unless res.next_page_token
    list_account_statuses(
        content_api, merchant_id, res.next_page_token, page_size)
  end
end

if __FILE__ == $0
  options = ArgParser.parse(ARGV)
  config, content_api = service_setup(options)
  unless config.is_mca
    puts "Configured merchant center account must be a multi-client account."
    exit
  end
  list_account_statuses(content_api, config.merchant_id)
end
