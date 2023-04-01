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
# Deletes several client accounts from the specified parent account, in a single
# batch.

require_relative "mca_common"

def delete_account_batch(content_api, merchant_id, account_ids)
  batch_id = 0
  requests = account_ids.map do |account_id|
    batch_id += 1
    Google::Apis::ContentV2_1::AccountsCustomBatchRequestEntry.new(
        merchant_id: merchant_id,
        account_id: account_id,
        batch_id: batch_id,
        method_prop: "delete")
  end

  batch_req =
      Google::Apis::ContentV2_1::AccountsCustomBatchRequest.new(
        entries: requests)

  content_api.custombatch_account(batch_req) do |res, err|
    if err
      puts "Overall batch call resulted in an error."
      handle_errors(err)
      exit
    end

    res.entries.each do |batch_resp|
      if batch_resp.errors
        puts "Batch item #{batch_resp.batch_id} resulted in an error."
        batch_resp.errors.each do |sub_err|
          handle_errors(sub_err)
        end
      else
        puts "Batch item #{batch_resp.batch_id} successful."
      end
      puts
    end
  end
end


if __FILE__ == $0
  options = ArgParser.parse(ARGV)

  unless ARGV.size >= 1
    puts "Usage: #{$0} ACCOUNT_ID_1 [ACCOUNT_ID_2 ...]"
    exit
  end
  account_ids = ARGV

  config, content_api = service_setup(options)
  unless config.is_mca
    puts "Merchant in configuration is not described as an MCA."
    exit
  end
  delete_account_batch(content_api, config.merchant_id, account_ids)
end
