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
# Deletes the specified product on the specified account.

require_relative "product_common"

def delete_product(content_api, merchant_id, product_id)
  content_api.delete_product(merchant_id, product_id) do |res, err|
    if err
      handle_errors(err)
      exit
    end

    puts "Product #{product_id} successfully deleted."
  end
end


if __FILE__ == $0
  options = ArgParser.parse(ARGV)

  unless ARGV.size == 1
    puts "Usage: #{$0} PRODUCT_ID"
    exit
  end
  product_id = ARGV[0]

  config, content_api = service_setup(options)
  delete_product(content_api, config.merchant_id, product_id)
end
