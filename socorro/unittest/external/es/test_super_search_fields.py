# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.

import copy
import datetime

import pytest

from socorro.lib import BadArgumentError
from socorro.external.es.super_search_fields import (
    FIELDS,
    is_doc_values_friendly,
    add_doc_values,
    SuperSearchFieldsModel,
)
from socorro.lib import datetimeutil
from socorro.unittest.external.es.base import ElasticsearchTestCase


# Uncomment these lines to decrease verbosity of the elasticsearch library
# while running unit tests.
# import logging
# logging.getLogger('elasticsearch').setLevel(logging.ERROR)
# logging.getLogger('requests').setLevel(logging.ERROR)


class TestIntegrationSuperSearchFields(ElasticsearchTestCase):
    """Test SuperSearchFields with an elasticsearch database containing fake data"""
    def setup_method(self, method):
        super().setup_method(method)

        config = self.get_base_config(cls=SuperSearchFieldsModel)
        self.api = SuperSearchFieldsModel(config=config)
        self.api.get_fields = lambda: copy.deepcopy(FIELDS)

    def test_get_fields(self):
        results = self.api.get_fields()
        assert results == FIELDS

    def test_get_missing_fields(self):
        config = self.get_base_config(
            cls=SuperSearchFieldsModel, es_index='socorro_integration_test_%W'
        )
        api = SuperSearchFieldsModel(config=config)

        fake_mappings = [
            # First mapping
            {
                config.elasticsearch_doctype: {
                    'properties': {
                        # Add a bunch of unknown fields.
                        'field_z': {
                            'type': 'string'
                        },
                        'namespace1': {
                            'type': 'object',
                            'properties': {
                                'field_a': {
                                    'type': 'string'
                                },
                                'field_b': {
                                    'type': 'long'
                                }
                            }
                        },
                        'namespace2': {
                            'type': 'object',
                            'properties': {
                                'subspace1': {
                                    'type': 'object',
                                    'properties': {
                                        'field_b': {
                                            'type': 'long'
                                        }
                                    }
                                }
                            }
                        },
                        # Add a few known fields that should not appear.
                        'processed_crash': {
                            'type': 'object',
                            'properties': {
                                'signature': {
                                    'type': 'string'
                                },
                                'product': {
                                    'type': 'string'
                                },
                            }
                        }
                    }
                }
            },

            # Second mapping to compare to the first
            {
                config.elasticsearch_doctype: {
                    'properties': {
                        'namespace1': {
                            'type': 'object',
                            'properties': {
                                'subspace1': {
                                    'type': 'object',
                                    'properties': {
                                        'field_d': {
                                            'type': 'long'
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            },
        ]

        now = datetimeutil.utc_now()
        indices = []

        try:
            # Using "2" here means that an index will be missing, hence testing
            # that it swallows the subsequent error.
            for i in range(2):
                date = now - datetime.timedelta(weeks=i)
                index = date.strftime(api.context.get_index_template())
                mapping = fake_mappings[i % len(fake_mappings)]

                api.context.create_index(index, mappings=mapping)
                indices.append(index)

            api = SuperSearchFieldsModel(config=config)
            missing_fields = api.get_missing_fields()
            expected = [
                'field_z',
                'namespace1.field_a',
                'namespace1.field_b',
                'namespace1.subspace1.field_d',
                'namespace2.subspace1.field_b',
            ]

            assert missing_fields['hits'] == expected
            assert missing_fields['total'] == 5

        finally:
            for index in indices:
                self.index_client.delete(index=index)

    def test_get_mapping(self):
        mapping = self.api.get_mapping()
        doctype = self.es_context.get_doctype()

        assert doctype in mapping
        properties = mapping[doctype]['properties']

        assert 'processed_crash' in properties
        assert 'raw_crash' in properties

        processed_crash = properties['processed_crash']['properties']

        # Check in_database_name is used.
        assert 'os_name' in processed_crash
        assert 'platform' not in processed_crash

        # Those fields have no `storage_mapping`.
        assert 'fake_field' not in properties['raw_crash']['properties']

        # Those fields have a `storage_mapping`.
        assert processed_crash['release_channel'] == {
            'analyzer': 'keyword',
            'type': 'string'
        }

        # Test nested objects.
        assert 'json_dump' in processed_crash
        assert 'properties' in processed_crash['json_dump']
        assert 'write_combine_size' in processed_crash['json_dump']['properties']
        assert processed_crash['json_dump']['properties']['write_combine_size'] == {
            'type': 'long',
            'doc_values': True
        }

        # Test overwriting a field.
        mapping = self.api.get_mapping(overwrite_mapping={
            'name': 'fake_field',
            'namespace': 'raw_crash',
            'in_database_name': 'fake_field',
            'storage_mapping': {
                'type': 'long'
            }
        })
        properties = mapping[doctype]['properties']

        assert 'fake_field' in properties['raw_crash']['properties']
        assert properties['raw_crash']['properties']['fake_field']['type'] == 'long'

    def test_test_mapping(self):
        """Much test. So meta. Wow test_test_. """
        # First test a valid mapping.
        mapping = self.api.get_mapping()
        assert self.api.test_mapping(mapping) is None

        # Insert an invalid storage mapping.
        mapping = self.api.get_mapping({
            'name': 'fake_field',
            'namespace': 'raw_crash',
            'in_database_name': 'fake_field',
            'storage_mapping': {
                'type': 'unkwown'
            }
        })
        with pytest.raises(BadArgumentError):
            self.api.test_mapping(mapping)

        # Test with a correct mapping but with data that cannot be indexed.
        self.index_crash({
            'date_processed': datetimeutil.utc_now(),
            'product': 'WaterWolf',
        })
        self.es_context.refresh()
        mapping = self.api.get_mapping({
            'name': 'product',
            'storage_mapping': {
                'type': 'long'
            }
        })
        with pytest.raises(BadArgumentError):
            self.api.test_mapping(mapping)


def get_fields():
    return FIELDS.items()


@pytest.mark.parametrize('name, properties', get_fields())
def test_validate_super_search_fields(name, properties):
    """Validates the contents of socorro.external.es.super_search_fields.FIELDS"""

    # FIXME(willkg): When we start doing schema stuff in Python, we should
    # switch this to a schema validation.

    property_keys = [
        'data_validation_type',
        'default_value',
        'description',
        'form_field_choices',
        'has_full_version',
        'in_database_name',
        'is_exposed',
        'is_mandatory',
        'is_returned',
        'name',
        'namespace',
        'permissions_needed',
        'query_type',
        'storage_mapping',
    ]

    # Assert it has all the keys
    assert sorted(properties.keys()) == sorted(property_keys)

    # Assert boolean fields have boolean values
    for key in ['has_full_version', 'is_exposed', 'is_mandatory', 'is_returned']:
        assert properties[key] in (True, False)

    # Assert data_validation_type has a valid value
    assert properties['data_validation_type'] in ('bool', 'datetime', 'enum', 'int', 'str')

    # Assert query_type has a valid value
    assert properties['query_type'] in ('bool', 'date', 'enum', 'flag', 'number', 'string')

    # The name in the mapping should be the same as the name in properties
    assert properties['name'] == name


@pytest.mark.parametrize('value, expected', [
    # No type -> False
    ({}, False),

    # object -> False
    ({'type': 'object'}, False),

    # Analyzed string -> False
    ({'type': 'string'}, False),
    ({'type': 'string', 'analyzer': 'keyword'}, False),

    # Unanalyzed string -> True
    ({'type': 'string', 'index': 'not_analyzed'}, True),

    # Anything else -> True
    ({'type': 'long'}, True),
])
def test_is_doc_values_friendly(value, expected):
    assert is_doc_values_friendly(value) == expected


def test_add_doc_values():
    data = {'type': 'short'}
    add_doc_values(data)
    assert data == {
        'type': 'short',
        'doc_values': True
    }

    data = {
        'fields': {
            'AsyncShutdownTimeout': {
                'analyzer': 'standard',
                'index': 'analyzed',
                'type': 'string',
            },
            'full': {
                'index': 'not_analyzed',
                'type': 'string',
            }
        },
        'type': 'multi_field',
    }
    add_doc_values(data)
    assert data == {
        'fields': {
            'AsyncShutdownTimeout': {
                'analyzer': 'standard',
                'index': 'analyzed',
                'type': 'string',
            },
            'full': {
                'index': 'not_analyzed',
                'type': 'string',
                'doc_values': True,
            }
        },
        'type': 'multi_field',
    }
